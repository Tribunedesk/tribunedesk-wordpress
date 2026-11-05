<?php
declare(strict_types=1);

use TribuneDesk\WordPress\Activator;
use TribuneDesk\WordPress\DeliveriesLog;

class Test_DeliveriesLog extends WP_UnitTestCase
{
    public function set_up(): void
    {
        parent::set_up();

        // Tabela musi powstac tutaj: test nie moze polegac na stanie zostawionym
        // we wspolnej bazie testowej przez cokolwiek spoza niego samego — aktywacje
        // wtyczki zrobiona przez wp-env przy starcie srodowiska, wczesniejszy
        // przebieg testow, czy reczna ingerencje czlowieka (instalator testowy
        // WordPressa kasuje miedzy przebiegami tylko tabele core'a, nie nasza).
        // Test tworzy wiec sam to, czego uzywa, zamiast zakladac, ze ktos juz to
        // zrobil za niego.
        Activator::activate();
    }

    public function test_record_and_recent(): void
    {
        DeliveriesLog::record('art-1', 100, 'created');
        DeliveriesLog::record('art-2', 101, 'updated');

        $rows = DeliveriesLog::recent();
        $this->assertCount(2, $rows);
        $this->assertSame('art-2', $rows[0]['article_id']);
        $this->assertSame('updated', $rows[0]['status']);
    }

    public function test_trims_to_max_rows(): void
    {
        for ($i = 0; $i < 25; $i++) {
            DeliveriesLog::record("art-$i", $i, 'created');
        }
        $rows = DeliveriesLog::recent();
        $this->assertCount(DeliveriesLog::MAX_ROWS, $rows);
        $this->assertSame(20, DeliveriesLog::MAX_ROWS);
        $this->assertSame('art-24', $rows[0]['article_id']);
    }

    public function test_kind_domyslnie_receive_i_wraca_z_recent(): void
    {
        DeliveriesLog::record('art-k1', 5, 'created');

        $rows = DeliveriesLog::recent();
        $this->assertSame(DeliveriesLog::KIND_RECEIVE, $rows[0]['kind']);
    }

    public function test_kind_notify_i_backfill_sa_zapisywane(): void
    {
        DeliveriesLog::record('art-k2', 6, 'ok', null, DeliveriesLog::KIND_NOTIFY);
        DeliveriesLog::record('batch:37', null, 'sent', 'ok=37', DeliveriesLog::KIND_BACKFILL);

        $rows = DeliveriesLog::recent();
        $this->assertSame(DeliveriesLog::KIND_BACKFILL, $rows[0]['kind']);
        $this->assertNull($rows[0]['post_id']);
        $this->assertSame('ok=37', $rows[0]['error_msg']);
        $this->assertSame(DeliveriesLog::KIND_NOTIFY, $rows[1]['kind']);
    }

    public function test_wiersze_media_nie_wypychaja_wierszy_receive(): void
    {
        DeliveriesLog::record('art-keep', 1, 'created');
        for ($i = 0; $i < 25; $i++) {
            DeliveriesLog::record('', null, 'sent', "accepted=$i", DeliveriesLog::KIND_MEDIA);
        }
        global $wpdb;
        $table = $wpdb->prefix . Activator::TABLE;
        $this->assertSame(1, (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE kind = 'receive'"));
        $this->assertSame(DeliveriesLog::MAX_ROWS, (int) $wpdb->get_var("SELECT COUNT(*) FROM $table WHERE kind = 'media'"));
        $this->assertSame('accepted=24', $wpdb->get_var("SELECT error_msg FROM $table WHERE kind = 'media' ORDER BY id DESC LIMIT 1"));
    }

    public function test_recent_zawiera_receive_mimo_wierszy_media(): void
    {
        for ($i = 0; $i < 3; $i++) {
            DeliveriesLog::record("art-r$i", $i, 'created');
        }
        for ($i = 0; $i < 25; $i++) {
            DeliveriesLog::record('', null, 'sent', "accepted=$i", DeliveriesLog::KIND_MEDIA);
        }
        $rows = DeliveriesLog::recent();
        $receive = array_filter($rows, static fn($r) => $r['kind'] === DeliveriesLog::KIND_RECEIVE);
        $this->assertCount(3, $receive);
        $this->assertCount(23, $rows);
        $this->assertSame(DeliveriesLog::KIND_MEDIA, $rows[0]['kind']);
    }
}
