# Setup steps (one time)

1. `laravel new promptedit-pixelforge` and choose the **Vue** starter kit, **Pest**, SQLite.
2. Copy everything from this folder (except `setup/`) into the project root.
3. `bootstrap/providers.php`: add `App\Providers\PixelforgeServiceProvider::class,`
4. `routes/web.php`: add at the bottom `require __DIR__.'/pixelforge.php';`
5. `routes/console.php`: add
   ```php
   use Illuminate\Support\Facades\Schedule;
   Schedule::command('pixelforge:sync')->everyMinute()->withoutOverlapping();
   ```
6. `app/Models/User.php`: apply `setup/User.snippet.php`.
7. `resources/js/components/AppSidebar.vue`: apply `setup/AppSidebar.snippet.ts`.
8. `.env` and `.env.example`: add the lines from `setup/env.example.snippet`.
9. `php artisan migrate && php artisan test`
