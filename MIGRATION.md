# Database Migration Guide

## For Existing Installations

If you're upgrading from an older version of WharfList, you need to run the migration script to update your database schema.

### What Changed?

#### New Features:
- **Many-to-Many List Relationships** - Subscribers can now belong to multiple lists
- **List-Specific Unsubscribe** - Users can unsubscribe from individual lists
- **Global Unsubscribe Tracking** - Track users who unsubscribe from all communications
- **Queue Job System** - Background email sending with progress tracking
- **Sending Speed Control** - Configurable email throttling to avoid rate limits

#### Database Changes:
1. Added `unsubscribed` column to `subscribers` table
2. Created `subscriber_lists` junction table for many-to-many relationships
3. Removed `list_id` column from `subscribers` table
4. Created `queue_jobs` table for email queue management
5. Added `unsubscribed` column to `subscriber_lists` for per-list tracking
6. Added performance indexes

### How to Migrate

#### Step 1: Backup Your Database
```bash
cp app/data/wharflist.db app/data/wharflist.db.backup
```

#### Step 2: Run Migration Script
```bash
php migrate.php
```

The script will:
- Check your current schema
- Add missing columns
- Create new tables
- Migrate existing data
- Add performance indexes
- Preserve all your existing data

#### Step 3: Verify Migration
Check the output for:
```
✅ Migration completed successfully!
Your database is now up to date.
```

### For New Installations

New installations automatically get the latest schema. No migration needed!

Just run:
```bash
php setup.php
```

### Rollback (if needed)

If something goes wrong:
```bash
# Restore from backup
cp app/data/wharflist.db.backup app/data/wharflist.db
```

### Need Help?

- Check the migration output for specific errors
- Ensure PHP has write permissions to the database file
- Make sure SQLite extension is installed

## Schema Overview

### New Tables

**subscriber_lists** (Junction Table)
- Links subscribers to multiple lists
- Tracks per-list unsubscribe status
- Enables flexible list management

**queue_jobs**
- Manages background email sending
- Tracks campaign progress
- Enables reliable email delivery

### Modified Tables

**subscribers**
- Removed: `list_id` (moved to junction table)
- Added: `unsubscribed` (global unsubscribe flag)
- Email is now unique globally (not per-list)

### Benefits

✅ Subscribers can be on multiple lists  
✅ Granular unsubscribe control  
✅ Better compliance (per-list unsubscribe)  
✅ Improved performance with indexes  
✅ Reliable email queue system  
✅ No data loss during migration
