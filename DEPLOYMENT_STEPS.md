# Deployment Steps After Migration Fixes

## 1. Navigate to Project Directory
```bash
cd /home/user/htdocs/srv1266167.hstgr.cloud
```

## 2. Pull Latest Changes (includes migration fixes)
```bash
git pull
```

## 3. Run Migrations
```bash
php artisan migrate --force
```

## 4. Clear Cache (if needed)
```bash
php artisan config:clear
php artisan cache:clear
php artisan route:clear
php artisan view:clear
```

## 5. Optimize (for production)
```bash
php artisan config:cache
php artisan route:cache
php artisan view:cache
```

## If Migrations Still Fail

If you encounter more "table already exists" errors:

1. **Quick Fix**: Add existence check to the failing migration:
   ```php
   if (!Schema::hasTable('table_name')) {
       Schema::create('table_name', function (Blueprint $table) {
           // ... table definition
       });
   }
   ```

2. **Check which migrations will fail**:
   ```bash
   php check_migrations.php
   ```

3. **Alternative**: If all tables already exist and match your migrations exactly, you can mark migrations as run:
   ```bash
   php artisan migrate:status  # See which are pending
   # Then manually insert into migrations table if needed
   ```
