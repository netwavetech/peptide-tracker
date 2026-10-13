#!/usr/bin/env python3

import argparse
import sys
from datetime import date, datetime, timedelta

import pymysql
from config import DB_CONFIG


def parse_args():
    parser = argparse.ArgumentParser(description="Build scheduled_doses for a specific date.")
    parser.add_argument("--date", dest="target_date", help="YYYY-MM-DD; defaults to today.")
    parser.add_argument("--verbose", action="store_true")
    return parser.parse_args()


def parse_date(value):
    if not value:
        return date.today()
    try:
        return datetime.strptime(value, "%Y-%m-%d").date()
    except ValueError:
        print("ERROR: --date must be YYYY-MM-DD", file=sys.stderr)
        sys.exit(2)


def log(verbose, message):
    if verbose:
        print(message)


def roll_repeating_cycle_anchors(cur, target_date, verbose=False):
    """
    cycle_anchor_date is the persisted CURRENT cycle start.

    The permanent/original protocol start remains effective_from. When a
    repeating ON/OFF cycle reaches its next cycle boundary, advance the anchor
    in the database to that new cycle start.

    Only persist rollover for today's real cron run. A manual --date test in
    the past/future must not change production cycle anchors.
    """
    if target_date != date.today():
        log(verbose, "Cycle-anchor rollover skipped for --date test run.")
        return 0

    cur.execute("""
        SELECT
            pi.protocol_item_id,
            pi.cycle_anchor_date,
            pi.run_days,
            COALESCE(pi.off_days, 0) AS off_days
        FROM protocol_items pi
        JOIN protocols p ON p.protocol_id = pi.protocol_id
        WHERE pi.active = 1
          AND p.active = 1
          AND pi.repeat_cycle = 1
          AND pi.run_days IS NOT NULL
          AND pi.cycle_anchor_date IS NOT NULL
    """)

    updates = []
    for row in cur.fetchall():
        anchor = row["cycle_anchor_date"]
        run_days = int(row["run_days"] or 0)
        off_days = int(row["off_days"] or 0)
        cycle_length = run_days + off_days

        if cycle_length <= 0 or target_date < anchor:
            continue

        elapsed = (target_date - anchor).days
        cycles_elapsed = elapsed // cycle_length

        if cycles_elapsed <= 0:
            continue

        new_anchor = anchor + timedelta(days=cycles_elapsed * cycle_length)
        updates.append((new_anchor, row["protocol_item_id"]))

    if updates:
        cur.executemany(
            "UPDATE protocol_items SET cycle_anchor_date=%s WHERE protocol_item_id=%s",
            updates,
        )
        for new_anchor, item_id in updates:
            log(verbose, f"Advanced protocol item {item_id} cycle start to {new_anchor}.")

    return len(updates)


def fetch_protocol_items(cur, target_date):
    cur.execute("""
        SELECT
            pi.protocol_item_id,
            pi.protocol_id,
            pi.formulation_id,
            f.name AS formulation_name,
            pi.schedule_type,
            pi.dose_amount,
            pi.dose_unit,
            pi.time_of_day,
            pi.effective_from,
            pi.effective_to,
            pi.cycle_anchor_date,
            pi.run_days,
            pi.off_days,
            pi.repeat_cycle
        FROM protocol_items pi
        JOIN protocols p ON p.protocol_id = pi.protocol_id
        JOIN formulations f ON f.formulation_id = pi.formulation_id
        WHERE pi.active = 1
          AND p.active = 1
          AND pi.effective_from <= %s
          AND (pi.effective_to IS NULL OR pi.effective_to >= %s)
          AND (p.start_date IS NULL OR p.start_date <= %s)
          AND (p.end_date IS NULL OR p.end_date >= %s)
        ORDER BY
          CASE pi.time_of_day
            WHEN 'morning' THEN 1
            WHEN 'afternoon' THEN 2
            WHEN 'evening' THEN 3
            WHEN 'night' THEN 4
            ELSE 5
          END,
          f.name
    """, (target_date, target_date, target_date, target_date))
    return cur.fetchall()


def fetch_weekdays(cur):
    cur.execute("SELECT protocol_item_id, weekday FROM protocol_item_weekdays")
    result = {}
    for row in cur.fetchall():
        result.setdefault(row["protocol_item_id"], set()).add(int(row["weekday"]))
    return result


def fetch_overrides(cur, target_date):
    cur.execute("""
        SELECT protocol_item_id, action, dose_amount, dose_unit, time_of_day
        FROM schedule_overrides
        WHERE scheduled_date=%s
    """, (target_date,))
    return {row["protocol_item_id"]: row for row in cur.fetchall()}


def cycle_is_active(item, target_date):
    run_days = item["run_days"]
    anchor = item["cycle_anchor_date"]

    if run_days is None:
        return True
    if anchor is None or target_date < anchor:
        return False

    elapsed = (target_date - anchor).days
    run_days = int(run_days)

    if not item["repeat_cycle"]:
        return elapsed < run_days

    off_days = int(item["off_days"] or 0)
    cycle_length = run_days + off_days
    if cycle_length <= 0:
        return False

    return (elapsed % cycle_length) < run_days


def normal_due(item, weekdays, target_date):
    if item["schedule_type"] != "scheduled":
        return False
    if target_date.isoweekday() not in weekdays.get(item["protocol_item_id"], set()):
        return False
    return cycle_is_active(item, target_date)


def build_schedule(conn, target_date, verbose=False):
    with conn.cursor() as cur:
        rolled = roll_repeating_cycle_anchors(cur, target_date, verbose)

        cur.execute("""
            DELETE FROM scheduled_doses
            WHERE scheduled_date=%s AND status='pending'
        """, (target_date,))

        items = fetch_protocol_items(cur, target_date)
        weekdays = fetch_weekdays(cur)
        overrides = fetch_overrides(cur, target_date)
        created = 0

        for item in items:
            override = overrides.get(item["protocol_item_id"])
            if override and override["action"] == "skip":
                continue

            due = bool(override and override["action"] == "add") or normal_due(item, weekdays, target_date)
            if not due:
                continue

            dose_amount = override["dose_amount"] if override and override["dose_amount"] is not None else item["dose_amount"]
            dose_unit = override["dose_unit"] if override and override["dose_unit"] else item["dose_unit"]
            time_of_day = override["time_of_day"] if override and override["time_of_day"] else item["time_of_day"]

            cur.execute("""
                INSERT IGNORE INTO scheduled_doses (
                    protocol_item_id, formulation_id, scheduled_date,
                    time_of_day, dose_amount, dose_unit, status
                ) VALUES (%s,%s,%s,%s,%s,%s,'pending')
            """, (
                item["protocol_item_id"], item["formulation_id"], target_date,
                time_of_day, dose_amount, dose_unit,
            ))
            created += cur.rowcount

        # Reconcile historical/manual administrations already recorded today.
        cur.execute("""
            UPDATE scheduled_doses sd
            JOIN (
                SELECT formulation_id, administered_date, MAX(administration_id) AS administration_id
                FROM administrations
                WHERE administered_date=%s
                GROUP BY formulation_id, administered_date
            ) existing
              ON existing.formulation_id = sd.formulation_id
             AND existing.administered_date = sd.scheduled_date
            SET sd.status='taken', sd.administration_id=existing.administration_id
            WHERE sd.scheduled_date=%s
              AND sd.status='pending'
        """, (target_date, target_date))
        reconciled = cur.rowcount

        conn.commit()
        return rolled, created, reconciled


def main():
    args = parse_args()
    target_date = parse_date(args.target_date)
    conn = pymysql.connect(**DB_CONFIG)
    try:
        rolled, created, reconciled = build_schedule(conn, target_date, args.verbose)
        print(
            f"Built {target_date}: {created} scheduled, {reconciled} reconciled as taken, "
            f"{rolled} cycle anchor(s) advanced."
        )
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


if __name__ == "__main__":
    main()
