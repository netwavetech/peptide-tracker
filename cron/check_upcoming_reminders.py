#!/usr/bin/env python3

import argparse
import sys
from datetime import date, datetime, timedelta

import pymysql
from config import DB_CONFIG


def parse_args():
    parser = argparse.ArgumentParser(description="Create/update vial and cycle reminders.")
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


def roll_repeating_cycle_anchors(cur, today, verbose=False):
    if today != date.today():
        log(verbose, "Cycle-anchor rollover skipped for --date test run.")
        return 0

    cur.execute("""
        SELECT pi.protocol_item_id, pi.cycle_anchor_date, pi.run_days,
               COALESCE(pi.off_days,0) AS off_days
        FROM protocol_items pi
        JOIN protocols p ON p.protocol_id=pi.protocol_id
        WHERE pi.active=1 AND p.active=1
          AND pi.repeat_cycle=1
          AND pi.run_days IS NOT NULL
          AND pi.cycle_anchor_date IS NOT NULL
    """)

    updates=[]
    for row in cur.fetchall():
        anchor=row["cycle_anchor_date"]
        length=int(row["run_days"] or 0)+int(row["off_days"] or 0)
        if length <= 0 or today < anchor:
            continue
        elapsed=(today-anchor).days
        cycles=elapsed // length
        if cycles <= 0:
            continue
        new_anchor=anchor+timedelta(days=cycles*length)
        updates.append((new_anchor,row["protocol_item_id"]))

    if updates:
        cur.executemany(
            "UPDATE protocol_items SET cycle_anchor_date=%s WHERE protocol_item_id=%s",
            updates,
        )
        for new_anchor,item_id in updates:
            log(verbose,f"Advanced protocol item {item_id} cycle start to {new_anchor}.")
    return len(updates)


def fetch_items(cur, today):
    cur.execute("""
        SELECT
            pi.protocol_item_id,
            pi.formulation_id,
            f.name AS formulation_name,
            pi.effective_from,
            pi.effective_to,
            pi.cycle_anchor_date,
            pi.run_days,
            COALESCE(pi.off_days,0) AS off_days,
            pi.repeat_cycle,
            COALESCE(pi.mix_reminder_days,0) AS mix_reminder_days,
            EXISTS (
                SELECT 1
                FROM prepared_vials pv
                JOIN vial_presentations vp ON vp.vial_presentation_id=pv.vial_presentation_id
                WHERE vp.formulation_id=pi.formulation_id
                  AND pv.status='active'
            ) AS has_active_vial
        FROM protocol_items pi
        JOIN protocols p ON p.protocol_id=pi.protocol_id
        JOIN formulations f ON f.formulation_id=pi.formulation_id
        WHERE pi.active=1
          AND p.active=1
          AND pi.schedule_type='scheduled'
          AND pi.effective_from <= %s
          AND (pi.effective_to IS NULL OR pi.effective_to >= %s)
          AND (p.start_date IS NULL OR p.start_date <= %s)
          AND (p.end_date IS NULL OR p.end_date >= %s)
        ORDER BY f.name
    """, (today,today,today,today))
    return cur.fetchall()


def complete_item_mix_reminders(cur, protocol_item_id):
    cur.execute("""
        UPDATE reminders
        SET status='completed', updated_at=CURRENT_TIMESTAMP
        WHERE protocol_item_id=%s
          AND reminder_type='mix'
          AND status='pending'
    """, (protocol_item_id,))
    return cur.rowcount


def upsert_mix(cur, item, reminder_date, target_date, message):
    cur.execute("""
        INSERT INTO reminders (
            protocol_item_id, formulation_id, reminder_type,
            reminder_date, target_date, message, status
        ) VALUES (%s,%s,'mix',%s,%s,%s,'pending')
        ON DUPLICATE KEY UPDATE
            reminder_date=VALUES(reminder_date),
            target_date=VALUES(target_date),
            message=VALUES(message),
            status='pending',
            updated_at=CURRENT_TIMESTAMP
    """, (
        item["protocol_item_id"], item["formulation_id"],
        reminder_date, target_date, message,
    ))


def reminder_target(item, today):
    """
    Return (reminder_date, target_date, message_suffix) or None.

    Current ON/run with no vial -> immediate reminder.
    OFF phase -> reminder window before next persisted cycle start.
    Future finite/repeating start -> reminder window before that start.
    Continuous current protocol -> immediate reminder if no active vial.
    """
    run_days=item["run_days"]
    anchor=item["cycle_anchor_date"] or item["effective_from"]
    reminder_days=int(item["mix_reminder_days"] or 0)

    if run_days is None:
        return today, today, "is currently running"

    run_days=int(run_days)
    if anchor is None:
        return None

    if today < anchor:
        reminder_date=anchor-timedelta(days=reminder_days)
        if reminder_date <= today <= anchor:
            return reminder_date, anchor, "starts soon"
        return None

    elapsed=(today-anchor).days

    if not item["repeat_cycle"]:
        if elapsed < run_days:
            return today, today, "is currently running"
        return None

    off_days=int(item["off_days"] or 0)
    cycle_length=run_days+off_days
    if cycle_length <= 0:
        return None

    # Because the daily cron persists cycle_anchor_date as the current cycle
    # start, elapsed should normally be within this one cycle.
    position=elapsed % cycle_length
    if position < run_days:
        return today, today, "is currently running"

    next_start=anchor+timedelta(days=cycle_length)
    reminder_date=next_start-timedelta(days=reminder_days)
    if reminder_date <= today <= next_start:
        return reminder_date, next_start, "next cycle starts soon"
    return None


def build(conn, today, verbose=False):
    with conn.cursor() as cur:
        rolled=roll_repeating_cycle_anchors(cur,today,verbose)

        cur.execute("""
            UPDATE reminders
            SET status='completed', updated_at=CURRENT_TIMESTAMP
            WHERE reminder_type='mix'
              AND status='pending'
              AND target_date < %s
        """, (today,))

        created=0
        for item in fetch_items(cur,today):
            if item["has_active_vial"]:
                cleared=complete_item_mix_reminders(cur,item["protocol_item_id"])
                log(verbose,f"{item['formulation_name']}: active vial present; cleared {cleared} reminder(s).")
                continue

            target=reminder_target(item,today)
            if target is None:
                complete_item_mix_reminders(cur,item["protocol_item_id"])
                log(verbose,f"{item['formulation_name']}: no reminder due.")
                continue

            reminder_date,target_date,suffix=target
            if target_date == today:
                message=f"{item['formulation_name']} has no active vial - make a new vial"
            else:
                message=(
                    f"{item['formulation_name']} next cycle starts {target_date.isoformat()} "
                    f"- mix next vial"
                )
            upsert_mix(cur,item,reminder_date,target_date,message)
            created += 1
            log(verbose,f"Reminder: {message}")

        conn.commit()
        return rolled,created


def main():
    args=parse_args()
    today=parse_date(args.target_date)
    conn=pymysql.connect(**DB_CONFIG)
    try:
        rolled,created=build(conn,today,args.verbose)
        print(f"Reminder check {today}: {created} reminder(s) active/updated, {rolled} cycle anchor(s) advanced.")
    except Exception:
        conn.rollback()
        raise
    finally:
        conn.close()


if __name__=='__main__':
    main()
