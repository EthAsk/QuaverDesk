<?php

namespace Tests\Support;

/** Finds installed modules' TenantFixtures (see that interface). */
final class ModuleFixtures
{
    /** @return list<class-string<TenantFixtures>> */
    public static function all(): array
    {
        $found = [];
        foreach (glob(dirname(__DIR__, 2).'/modules/*/Tests/*TenantFixtures.php') ?: [] as $file) {
            $class = 'Modules\\'.basename(dirname($file, 2)).'\\Tests\\'.basename($file, '.php');
            if (class_exists($class) && is_subclass_of($class, TenantFixtures::class)) {
                $found[] = $class;
            }
        }

        return $found;
    }
}
