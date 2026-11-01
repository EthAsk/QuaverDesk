<?php

namespace Tests\Feature;

use Tests\TestCase;

class VersionTest extends TestCase
{
    public function test_the_version_comes_from_the_version_file(): void
    {
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', config('quaverdesk.version'));
        $this->assertSame(trim(file_get_contents(base_path('VERSION'))), config('quaverdesk.version'));
    }

    public function test_the_changelog_has_an_entry_for_this_version(): void
    {
        $this->assertStringContainsString('## '.config('quaverdesk.version').' (', file_get_contents(base_path('CHANGELOG.md')));
    }
}
