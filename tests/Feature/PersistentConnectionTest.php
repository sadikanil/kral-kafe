<?php

namespace Tests\Feature;

use PDO;
use Tests\TestCase;

/**
 * Faz 2 / H (P13): her istek Supabase'e yeniden baglaniyor (TCP + TLS +
 * SCRAM, 5-7 gidis-donus). vercel-php kapsayici basina tek "php -S" sureci
 * tuttugu icin kalici PDO baglantisi bir sonraki istekte yeniden kullanilir.
 *
 * Varsayilan KAPALI: donmus kapsayicidan donuste ilk sorgu olu sokete
 * carpabilir ve her sicak kopya bir session pooler yeri tutar. Once bir
 * onizlemede DB_PERSISTENT=true ile Server-Timing'e bakilarak acilir.
 */
class PersistentConnectionTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('DB_PERSISTENT');
        putenv('DB_EMULATE_PREPARES');

        parent::tearDown();
    }

    private function secenekler(): array
    {
        $this->refreshApplication();

        return config('database.connections.pgsql.options');
    }

    public function test_connections_are_not_persistent_by_default(): void
    {
        $this->assertArrayNotHasKey(PDO::ATTR_PERSISTENT, $this->secenekler());
    }

    public function test_a_deployment_can_turn_persistent_connections_on(): void
    {
        putenv('DB_PERSISTENT=true');

        $this->assertTrue($this->secenekler()[PDO::ATTR_PERSISTENT] ?? false);
    }

    /** Iki ayar birbirini ezmemeli (transaction pooler + kalici baglanti). */
    public function test_it_combines_with_prepared_statement_emulation(): void
    {
        putenv('DB_PERSISTENT=true');
        putenv('DB_EMULATE_PREPARES=true');

        $secenekler = $this->secenekler();

        $this->assertTrue($secenekler[PDO::ATTR_PERSISTENT] ?? false);
        $this->assertTrue($secenekler[PDO::ATTR_EMULATE_PREPARES] ?? false);
    }
}
