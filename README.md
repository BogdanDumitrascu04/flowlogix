# FlowLogix

A role-based dashboard for warehouse operations, built with PHP and MySQL.

In a warehouse, goods move through three flows: **inbound** (receiving), **pick & pack** (preparing orders) and **outbound** (shipping). Each person in the team needs a different view of these flows. A shift supervisor wants to see what is blocked right now, and a logistics manager wants to see how many operations were finished on time this month. FlowLogix shows each role the numbers and delays it needs.

This was my bachelor's thesis project (Business Informatics, Faculty of Economics and Business Administration, West University of Timișoara, 2026). The interface is in Romanian.

![Logistics manager dashboard](docs/screenshots/manager-dashboard.png)

## Roles

| Role | Login | What the role sees |
|---|---|---|
| Shift supervisor (*șef de tură*) | `tura` | Active receptions, orders in progress, overdue shipments and blocked items in the current shift |
| Warehouse manager (*șef de depozit*) | `depozit` | Workload and backlog for each flow, warehouse reports |
| Logistics manager | `manager` | KPIs for all flows: on-time rate, delays, performance per flow, reports |
| Administrator | `admin` | User management (add, edit, delete) and demo data for the current day |

Each page checks the role saved in the session. A user who opens a page that belongs to another role gets a "403 Access denied" page.

## Features

- A dashboard for each role, with a period filter: today, last 7 days, last 30 days, current month
- Overdue and blocked items are calculated from the dates (`expected_finished_date`, `planned_date`), so there is no separate alerts table to keep in sync
- An hourly order simulator that keeps the demo data moving (see [Order simulator](#order-simulator))
- CSV exports and printable reports
- Charts with Chart.js
- Five color themes, saved in the browser

## Screenshots

| Shift supervisor | Warehouse manager |
|---|---|
| ![Shift supervisor dashboard](docs/screenshots/shift-dashboard.png) | ![Warehouse manager dashboard](docs/screenshots/warehouse-dashboard.png) |
| **Login** | **User administration** |
| ![Login page](docs/screenshots/login.png) | ![User administration](docs/screenshots/admin-users.png) |

## Tech stack

- **Back end:** PHP 8 with MySQLi, no framework
- **Database:** MySQL 8
- **Front end:** HTML, CSS and vanilla JavaScript, Chart.js, Boxicons
- **Local server:** WampServer (Apache, PHP, MySQL)

## Database

```mermaid
erDiagram
    users {
        int id PK
        varchar name UK
        varchar password "bcrypt hash"
        enum role "tura, depozit, manager, admin"
    }
    inbound_orders {
        int id PK
        varchar reception_number UK
        varchar supplier_name
        enum status
        enum priority
        datetime created_at
        datetime finished_at
        date expected_finished_date
    }
    pickpack_orders {
        int id PK
        varchar order_number UK
        varchar operator_name
        int items_count
        enum status
        datetime created_at
        datetime finished_at
        date expected_finished_date
    }
    outbound_orders {
        int id PK
        varchar order_number UK
        varchar destination
        datetime planned_date
        int delay_minutes
        enum status
        datetime created_at
        datetime finished_at
    }
    simulator_runs {
        datetime run_hour PK
        int created_orders
        int advanced_orders
        datetime ran_at
    }
```

Each flow has its own table. An order counts as **finished** when `finished_at` is set. It counts as **overdue** when it is not finished and its expected date (`expected_finished_date` or `planned_date`) has passed. `created_at` is indexed, because every period filter uses it.

## Run it locally

1. Install [WampServer](https://www.wampserver.com/) and copy this folder to `C:\wamp64\www\flowlogix`.
2. Open phpMyAdmin (`http://localhost/phpmyadmin`), create a database named `flowlogix` with the collation `utf8mb4_unicode_ci`, then import `database/flowlogix.sql`.
3. Copy `config.example.php` to `config.php`. The example uses the default WampServer settings (user `root`, no password); change them if yours are different.
4. Open `http://localhost/flowlogix/` and log in with one of the demo accounts.

Demo accounts: `admin`, `manager`, `depozit`, `tura`. The password for all of them is `demo123`. These are test accounts for the demo data only.

The demo data contains about 1,500 orders over four months. On import, the dates are moved forward so that the most recent day of activity is the day you import the file.

## Order simulator

There is no real warehouse system behind the demo, so `jobs/simulate_orders.php` plays that role. It is meant to run once an hour, between 06:00 and 22:00. Each run:

1. creates a few new orders in each flow (receptions, pick & pack orders, shipments);
2. moves open orders from the last two days forward: some are finished, some get an operator, and shipments that miss their planned time become delayed.

Everything in one run happens inside a single transaction, so an hour is either simulated completely or not at all. The table `simulator_runs` uses the hour as its primary key, so the database refuses to simulate the same hour twice, even if the job starts twice.

Run it by hand (`--force` also works outside working hours):

```
php jobs/simulate_orders.php --force
```

To run it every hour on Windows, create a task in Task Scheduler that starts `C:\wamp64\bin\php\php8.2.29\php.exe` with the argument `C:\wamp64\www\flowlogix\jobs\simulate_orders.php`. On a Linux server, a cron line does the same job:

```
0 * * * * php /var/www/flowlogix/jobs/simulate_orders.php
```

## Project structure

| Path | Purpose |
|---|---|
| `login.php`, `authenticate.php` | Login form and login check |
| `dashboard-*.php` | Home page for each role |
| `inbound.php`, `pickpack.php`, `outbound.php` | Detailed pages for each flow |
| `raport-*.php`, `rapoarte.php`, `export_report.php` | Reports and CSV exports |
| `admin.php` | User management |
| `flow_helpers.php` | Shared functions: period filters, the `flx_query()` helper for prepared statements, status rules |
| `database/` | Schema with demo data, and the password migration script |
| `jobs/simulate_orders.php` | Hourly order simulator |

## Improvements after the thesis

After the thesis, I fixed two weaknesses that I had listed as future work:

- **Passwords** were stored as plain text. They are now saved as bcrypt hashes with `password_hash()` and checked with `password_verify()`. The script `database/migrate_passwords.php` converted the existing users once. It skips passwords that are already hashed, so running it twice does no harm.
- **SQL queries** pasted the period filter into the SQL text. The period came from a fixed list, so it could not be used for SQL injection, but code and values were mixed in one string. Every period filter is now a prepared statement: `created_at BETWEEN ? AND ?`, with the two dates bound as parameters.

Still open: the forms have no CSRF tokens, and the login page has no limit on failed attempts.

## How it was built

I designed FlowLogix: the roles, the flows and the KPIs come from my work as a team leader in a logistics warehouse. I wrote the code with the help of an AI coding assistant. I defined the requirements and the rules, and I reviewed and tested every change. I can explain any part of the code.
