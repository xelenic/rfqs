---
paths:
  - 'database/migrations/**'
---

# Migrations

## A new table must be classified for live updates
Put every new table in App\LiveVersion::WATCHED_TABLES (pages show it, so its writes make live pages refresh) or IGNORED_TABLES (plumbing/access control). tests/Feature/LiveUpdatesTest.php fails until it is classified, so a page's data can't be silently left out of live updates.

## Keep migrations driver-agnostic — tests run on in-memory SQLite
Use Schema::hasIndex()/hasColumn(), never information_schema or other MySQL-only queries: the suite (and any fresh sqlite database) runs every migration on SQLite, and one MySQL-only query fails every test at setup. Bit us in 2026_09_19_153400_make_rfq_user_one_row_per_part.php.
