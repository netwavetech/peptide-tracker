# Peptide Research Tracker

A self-hosted PHP/MySQL dashboard for tracking a personal peptide research workflow.

The application is designed around four related pieces of information:

- **Protocols** — what is intended to be used and when
- **Administrations** — what was actually recorded as taken
- **Vials and inventory** — which vial sizes are in stock and which vials are currently active
- **History and visualizations** — weight history, consumption history, schedule completion, and cumulative half-life charts

The project intentionally uses a simple stack: PHP, MySQL, Bootstrap, Chart.js, and two optional Python cron jobs. There is no framework and no user-account system.

> **Important:** This repository is a tracking application. Values such as dose, half-life, protocol dates, cycle lengths, and formulation composition are user-supplied research configuration. The application does not validate whether those values are medically or scientifically appropriate.

---

## Features

### Dashboard

The main dashboard provides:

- current weight and quick weight entry
- daily scheduled doses grouped by time of day
- editable actual dose when marking a scheduled dose as taken
- as-needed doses
- manual "Add Today's Dose" support
- weekly cumulative half-life visualization
- active prepared vial tracking
- unopened vial inventory
- reminders for preparing a new vial
- summary counts for taken, pending, active vials, and inventory

### Protocols

`protocols.php` provides a central protocol editor.

Each formulation can be configured as either:

- **Scheduled**
- **As needed**

Scheduled protocols can include:

- dose and dose unit
- time of day
- weekday selection
- original protocol start date
- optional protocol end date
- no cycle
- finite run
- repeating ON/OFF cycle
- current cycle start date
- days ON
- days OFF
- mix reminder lead time
- notes
- active/inactive state

The application keeps `effective_from` as the original start of that protocol version and uses `cycle_anchor_date` as the **current cycle start** for cycling protocols.

### Inventory and active vials

Inventory is tracked by exact vial presentation.

For example, these are separate inventory items:

- Retatrutide 10 mg
- Retatrutide 20 mg

An active vial can only be created from unopened inventory. Creating an active vial consumes one vial from that exact presentation.

Multiple active vials of the same formulation are allowed.

When an active vial is marked **Used It All**:

- only that vial is closed
- other active vials of the same formulation remain active
- if no active vial remains and the protocol is currently in an ON/run period, a new-vial reminder can be created immediately
- if the protocol is currently OFF-cycle, the reminder cron waits until the configured reminder window before the next cycle

Once a new vial becomes active, pending automatic mix reminders for that formulation are completed automatically.

### History

`history.php` includes:

- body-weight history
- total weight-loss history
- percentage weight loss
- weekly weight change
- per-formulation consumption history
- long-term cumulative half-life history using up to six months of administrations
- weekly schedule completion
- recent administration history

---

# Requirements

Recommended environment:

- PHP 8.4+
- PHP `mysqli`
- MySQL 8.0+
- Apache or another PHP-capable web server
- Python 3.9+ for cron jobs
- Python package `PyMySQL`
- HTTPS

The interface currently loads Bootstrap, Bootstrap Icons, and Chart.js from CDNs, so the browser needs Internet access unless those assets are hosted locally.

---

# Repository layout

```text
.
├── actions/
│   ├── add-active-vial.php
│   ├── add-inventory.php
│   ├── add-peptide.php
│   ├── add-today-dose.php
│   ├── add-weight.php
│   ├── finish-vial.php
│   ├── save-protocol.php
│   ├── skip-dose.php
│   ├── take-as-needed.php
│   └── take-dose.php
├── assets/
│   └── app.css
├── cron/
│   ├── build_daily_schedule.py
│   ├── check_upcoming_reminders.py
│   └── config.example.py
├── database/
│   └── create_database.sql
├── includes/
│   ├── auth.php
│   ├── config.php
│   └── functions.php
├── history.php
├── index.php
├── login.php
├── logout.php
├── protocols.php
├── .gitignore
└── README.md
```

---

# Installation

## 1. Create the database

Run:

```bash
mysql -u root -p < database/create_database.sql
```

The supplied SQL creates the database:

```text
peptides
```

If you want another database name, change the `CREATE DATABASE`, `USE`, and PHP/Python configuration accordingly.

The SQL intentionally does **not** contain historical backfill data. The application can be initialized and populated manually.

It does create one empty active master protocol named:

```text
Current Research Protocol
```

The application expects an active master protocol to exist before protocol items can be added.

## 2. Configure PHP database access

Edit:

```text
includes/config.php
```

Example:

```php
$dbHost = '127.0.0.1';
$dbUser = 'peptides_user';
$dbPass = 'CHANGE_ME';
$dbName = 'peptides';
```

Create a MySQL account with only the permissions this application needs rather than using the MySQL root account.

## 3. Configure the application password

Edit:

```text
includes/auth.php
```

Change:

```php
const APP_PASSWORD = 'ChangeMe123!';
```

to your own password.

This is intentionally a **single-password session gate**, not a full authentication system. There are no usernames, password resets, account roles, or database-backed users.

Use HTTPS.

Do not commit your real production password to GitHub.

## 4. Deploy the PHP application

Copy the repository contents into your PHP web root.

Make sure PHP can create sessions and connect to MySQL.

Then open:

```text
https://your-host/
```

The application will redirect to `login.php` until the access password has been entered.

---

# Python cron configuration

The two Python scripts import a local `config.py`.

Copy:

```bash
cp cron/config.example.py cron/config.py
```

Then edit the credentials.

Install PyMySQL:

```bash
python3 -m pip install PyMySQL
```

`cron_updates/config.py` is ignored by Git and should contain the real database credentials.

## Daily schedule builder

`build_daily_schedule.py` creates expected scheduled doses for a date.

Typical cron:

```cron
5 0 * * * /usr/bin/python3 /path/to/project/cron/build_daily_schedule.py >/dev/null 2>&1
```

Manual testing:

```bash
python3 cron/build_daily_schedule.py --date 2026-10-13 --verbose
```

The scheduler:

- reads active protocol items
- checks weekday rules
- checks cycle state
- applies schedule overrides
- creates pending scheduled-dose rows
- reconciles an existing administration for the same formulation/date as taken
- advances persisted repeating-cycle start dates when a real cycle rolls over

A `--date` test run does **not** rewrite production cycle anchors unless the requested date is the actual current date.

## Reminder builder

`check_upcoming_reminders.py` manages automatic vial reminders.

Suggested cron:

```cron
0 6 * * * /usr/bin/python3 /path/to/project/cron/check_upcoming_reminders.py >/dev/null 2>&1
```

The reminder job:

- clears automatic mix reminders when an active vial exists
- creates an immediate reminder when a currently-running protocol has no active vial
- waits during OFF-cycle periods
- creates a reminder before the next cycle according to `mix_reminder_days`
- advances the persisted cycle start when a repeating cycle rolls over

---

# First-time setup

Because there is no seed/backfill dataset, a clean installation can be configured entirely from the application.

A practical order is:

1. Log in.
2. Use **Inventory → Add Peptide / Vial Size**.
3. Create each peptide/formulation.
4. Enter the first vial size.
5. Optionally create its protocol at the same time.
6. Add unopened inventory.
7. Make the desired vial active.
8. Repeat for the remaining formulations.
9. Use the Protocols page to refine schedules, cycles, and as-needed settings.
10. Start recording doses and weight.

---

# Adding a new peptide

The **Add Peptide / Vial Size** dialog creates a normal single-component formulation.

For a new peptide it creates records in:

- `peptides`
- `formulations`
- `formulation_components`
- `vial_presentations`

You may also create its first protocol in the same dialog.

For an existing formulation, the same dialog can add another vial size without creating another peptide.

## Vial sizes

Vial sizes are independent presentations.

A formulation can therefore have:

```text
10 mg
20 mg
```

at the same time, with inventory tracked separately for each size.

---

# Half-life configuration

Half-life is one of the most important configuration values for the graphing features.

## Storage

The value is stored in:

```text
peptides.half_life_hours
```

It is always stored in **hours**, including values shorter than one hour or longer than one day.

Examples:

| Human-readable half-life | `half_life_hours` |
|---|---:|
| 30 minutes | `0.5` |
| 45 minutes | `0.75` |
| 10 hours | `10` |
| 24 hours | `24` |
| 6 days | `144` |

The field is a decimal value and can be left `NULL`.

## What happens if half-life is blank?

The peptide still works for:

- inventory
- active vials
- protocols
- schedule generation
- administration history
- consumption history

It simply will not be included in the cumulative half-life graphs until a half-life is provided.

## Adding half-life from the UI

When creating a new peptide, enter its half-life in the **Half-life (hours)** field.

The application does not currently provide a separate half-life editor for an already-created peptide.

To add or change it later, update the database directly:

```sql
UPDATE peptides
SET half_life_hours = 144
WHERE name = 'Example Peptide';
```

You can also clear it:

```sql
UPDATE peptides
SET half_life_hours = NULL
WHERE name = 'Example Peptide';
```

## How the graph is calculated

For every recorded administration, the application estimates the amount remaining using:

```text
remaining = dose × 0.5 ^ (elapsed_hours / half_life_hours)
```

For repeated administrations, the remaining amount from every previous dose is added together.

This creates a **cumulative/stacking curve**, rather than treating the newest administration as if it replaced the previous one.

### Dashboard graph

The dashboard half-life graph:

- displays the current Saturday-Friday week
- calculates hourly points
- includes prior administrations that can still contribute to the current amount
- uses the actual current time for the current-hour point

### History graph

The History page long-term half-life graph:

- uses up to six months of recorded administrations
- creates daily graph points
- cumulatively adds remaining amount from all prior doses within the calculation
- uses current time for today's point

## Changing a half-life affects old graphs

Half-life curves are calculated dynamically from administration history.

If you change `half_life_hours`, the application will recalculate historical curves using the new value. It does **not** preserve a separate historical half-life value that was in effect when each administration was recorded.

This is intentional for the current version.

If versioned half-life assumptions are needed in the future, they should be stored in a separate effective-dated table rather than replacing `peptides.half_life_hours`.

## Units

Recorded doses currently support:

- `mg`
- `mcg`

Half-life calculations normalize those units internally before plotting.

## Missing administration times

If an imported or manually-created historical administration does not contain an exact clock time, the graph treats it as **12:00 PM** for calculation purposes.

Administrations entered through the application normally contain their actual recorded time.

---

# Formulations and blends

Most entries are simple one-peptide formulations.

The database separates:

```text
peptides
formulations
formulation_components
```

so blends can contain multiple peptide components.

## Single-component peptide

A new peptide created by the UI gets one component mapping:

```text
Formulation → Peptide
```

with a `NULL` component amount. This means the entire recorded formulation dose is treated as that peptide dose.

## Multi-component formulations

The current Add Peptide dialog does **not** build multi-component blends.

A custom blend must currently be configured directly in the database or through a future blend-management UI.

For normal blends where component amounts are populated, the half-life code proportionally distributes the recorded formulation dose according to `formulation_components`.

### KLOW special handling

The current application contains explicit KLOW handling.

A recorded KLOW entry of `1 mg` is interpreted for half-life visualization as:

```text
GHK-Cu   5 mg
BPC-157  1 mg
TB-500   1 mg
KPV      1 mg
```

That mapping is application-specific logic in the PHP graph calculations. If you remove or rename KLOW, or want another blend to use a non-proportional entry convention, update that mapping in the code.

---

# Protocol model

There is a master record in:

```text
protocols
```

and individual formulation settings in:

```text
protocol_items
```

Weekday selections are stored in:

```text
protocol_item_weekdays
```

ISO weekdays are used:

```text
1 = Monday
2 = Tuesday
3 = Wednesday
4 = Thursday
5 = Friday
6 = Saturday
7 = Sunday
```

## Scheduled vs as needed

A protocol item can be:

```text
scheduled
as_needed
```

Scheduled items can generate rows in `scheduled_doses`.

As-needed items do not automatically generate daily rows. They appear in the dashboard's As Needed section only when there is an active vial for that formulation.

A formulation can be switched between scheduled and as-needed from the Protocols page.

---

# Cycle model

The application distinguishes the original protocol start from the current repeating-cycle start.

## `effective_from`

This is the start date of that protocol version.

It is also useful as the historical/original start shown on the Protocols page.

## `cycle_anchor_date`

For cycling protocols, this is the **current cycle start date**.

For repeating cycles, the cron job advances this field when the next cycle begins.

Example:

```text
Current cycle starts: 2026-10-10
Days ON:             84
Days OFF:            28
```

The current ON period is calculated from that stored cycle start.

After the full 112-day ON/OFF cycle rolls over, the cron advances `cycle_anchor_date` to the new cycle start.

This persisted date is also used by the reminder workflow so new-vial reminders can be tied to real upcoming cycle dates.

## Finite runs

For finite runs:

- `run_days` contains the run length
- `repeat_cycle = 0`
- no repeating rollover occurs

## Continuous protocols

Continuous protocols have:

```text
run_days = NULL
```

They are considered in-run whenever the protocol is effective.

---

# Dose history and protocol versioning

`scheduled_doses` stores the expected/planned dose.

`administrations` stores the actual recorded dose.

If the user marks a scheduled dose as taken but changes the dose amount:

1. the original scheduled dose stays unchanged
2. the administration records the actual amount
3. the current protocol item is closed
4. a new protocol version starts the next day with the new dose
5. weekday and cycle configuration are carried forward

This prevents historical schedules from changing retroactively.

---

# Inventory data model

Inventory uses:

```text
vial_presentations
inventory_lots
inventory_vial_events
prepared_vials
```

`inventory_lots.quantity_received` records how many vials entered inventory.

Opening or discarding vials creates rows in `inventory_vial_events`.

Unopened inventory is calculated rather than stored:

```text
quantity_received - opened events - discarded events
```

This makes the inventory count auditable.

---

# Automatic reminders

Automatic reminders are stored in:

```text
reminders
```

The current automated reminder type is primarily:

```text
mix
```

The application suppresses/completes pending mix reminders whenever an active vial exists.

If there is no active vial:

- current ON/run period → reminder is due now
- OFF period → reminder waits until the configured lead time before the next cycle
- future cycle → reminder becomes visible inside its reminder window
- completed finite run → no replacement-vial reminder

---

# Manual schedule overrides

`schedule_overrides` supports date-specific exceptions to the normal scheduler.

Supported actions are:

```text
add
skip
```

The dashboard's **Add Today's Dose** feature can also create a scheduled row manually for a currently-effective protocol that has an active vial.

---

# Password protection and security

This project intentionally uses lightweight access control.

It does provide:

- one shared password
- PHP session protection
- session ID regeneration after login
- CSRF tokens on state-changing forms
- prepared statements for database changes

It does **not** provide:

- usernames
- password hashing/database accounts
- MFA
- account lockout
- role-based permissions
- password recovery
- audit identities for multiple users

Recommended deployment:

- always use HTTPS
- use a unique application password
- restrict the database user to this database
- do not expose MySQL publicly
- keep PHP and dependencies updated
- do not commit production credentials to Git
- consider HTTP Basic Auth, VPN, SSO, or a real authentication layer if exposed beyond a trusted environment

---

# GitHub / secrets

Before publishing a repository, verify that these do not contain real secrets:

```text
includes/config.php
includes/auth.php
cron_updates/config.py
```

`cron_updates/config.py` is ignored by the included `.gitignore`.

The committed PHP files currently contain placeholder credentials/passwords. Keep them as placeholders in a public repository.

---

# Database backups

This application contains historical administration and weight data that cannot be reconstructed automatically.

Back up the MySQL database regularly.

Example:

```bash
mysqldump -u peptides_user -p writes_peptides > peptide-research.sql
```

---

# No backfill required

The GitHub setup deliberately includes no historical backfill scripts.

Everything needed for a new installation can be created manually through the application:

- peptides
- vial presentations
- inventory
- protocols
- active vials
- dose history going forward
- weights going forward

Historical administrations can still be inserted directly into the database if desired, but that is outside the default installation workflow.

---

# Development notes

The application currently uses server-local dates/times from PHP and Python.

Make sure:

- PHP timezone
- server timezone
- Python environment timezone
- MySQL timezone expectations

are aligned for your deployment.

This is especially important for:

- today's dose
- current half-life point
- cycle rollover
- reminders
- administration timestamps

---

# License

No open-source license is included by default.

If this repository will be publicly distributed, add the license that matches how you want others to be able to use, modify, and redistribute the project.
