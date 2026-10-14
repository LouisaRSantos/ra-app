<?php

namespace Tests\Feature;

use App\Enums\ExtractionEngine;
use App\Enums\ExtractionStatus;
use App\Models\ExtractionRun;
use App\Models\Remittance;
use App\Models\RemittanceFile;
use App\Models\RemittanceInvoice;
use App\Models\SalesInvoice;
use App\Models\User;
use Database\Seeders\AccessSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(AccessSeeder::class);
    }

    private function user(string $role): User
    {
        return tap(User::factory()->create())->assignRole($role);
    }

    public function test_relationships_and_exact_money(): void
    {
        $owner = $this->user('Account Specialist');
        $remittance = Remittance::factory()->completed()->for($owner)->create(['total_amount' => '1234.50']);
        RemittanceFile::factory()->for($remittance)->create(['position' => 1]);
        RemittanceFile::factory()->for($remittance)->create(['position' => 0]);
        $si = SalesInvoice::factory()->create();
        RemittanceInvoice::factory()->for($remittance)->for($si)->create(['amount' => '99.10', 'cwt_amount' => '1.99']);
        ExtractionRun::factory()->for($remittance)->create(['engine' => ExtractionEngine::Groq]);
        $latest = ExtractionRun::factory()->for($remittance)->create(['engine' => ExtractionEngine::Claude]);

        $remittance = $remittance->fresh();
        $this->assertSame('1234.50', $remittance->total_amount);
        $this->assertSame([0, 1], $remittance->files->pluck('position')->all());
        $this->assertSame('99.10', $remittance->lines->first()->amount);
        $this->assertSame($si->si_number, $remittance->lines->first()->salesInvoice->si_number);
        $this->assertSame($latest->id, $remittance->latestRun->id);
        $this->assertSame(ExtractionStatus::Completed, $remittance->status);
        $this->assertTrue($owner->is($remittance->user));
        $this->assertNotEmpty($remittance->uuid);
        $this->assertMatchesRegularExpression('/^RA-[0-9]{6}-[A-Z0-9]{5}$/', $remittance->reference);
        $this->assertSame('4', $remittance->uuid[14], 'public ids must be random v4, not time-ordered');
        $this->assertSame($remittance->uuid, $remittance->getRouteKey());
    }

    public function test_constraints(): void
    {
        $remittance = Remittance::factory()->create();
        $si = SalesInvoice::factory()->create();

        // an SI number exists once
        $this->assertThrows(fn () => SalesInvoice::create(['si_number' => $si->si_number]), QueryException::class);

        // an invoice appears once per remittance, a page position once per remittance
        RemittanceInvoice::factory()->for($remittance)->for($si)->create();
        $this->assertThrows(fn () => RemittanceInvoice::factory()->for($remittance)->for($si)->create(), QueryException::class);
        RemittanceFile::factory()->for($remittance)->create(['position' => 0]);
        $this->assertThrows(fn () => RemittanceFile::factory()->for($remittance)->create(['position' => 0]), QueryException::class);

        // a user who owns transactions cannot be deleted
        $this->assertThrows(fn () => $remittance->user->delete(), QueryException::class);
    }

    public function test_deleting_a_remittance_removes_its_children_only(): void
    {
        $remittance = Remittance::factory()->create();
        $si = SalesInvoice::factory()->create();
        RemittanceInvoice::factory()->for($remittance)->for($si)->create();
        RemittanceFile::factory()->for($remittance)->create();
        ExtractionRun::factory()->for($remittance)->create();

        $remittance->delete();

        $this->assertSame(0, RemittanceInvoice::count() + RemittanceFile::count() + ExtractionRun::count());
        $this->assertSame(1, SalesInvoice::count());
    }

    public function test_remittance_scope_follows_ownership_and_view_all(): void
    {
        $a = $this->user('Account Specialist');
        $b = $this->user('Account Specialist');
        $admin = $this->user('Admin');
        $mine = Remittance::factory()->for($a)->create();
        Remittance::factory()->for($b)->create();

        $this->assertSame([$mine->id], Remittance::visibleTo($a)->pluck('id')->all());
        $this->assertCount(2, Remittance::visibleTo($admin)->get());
    }

    public function test_file_scope_for_each_role(): void
    {
        $a = $this->user('Account Specialist');
        $b = $this->user('Account Specialist');
        $admin = $this->user('Admin');
        $plain = $this->user('User');

        $aPrivate = RemittanceFile::factory()->for(Remittance::factory()->for($a))->create();
        $aPublic = RemittanceFile::factory()->public()->for(Remittance::factory()->for($a))->create();
        $bPrivate = RemittanceFile::factory()->for(Remittance::factory()->for($b))->create();
        $bPublic = RemittanceFile::factory()->public()->for(Remittance::factory()->for($b))->create();

        $ids = fn (User $u) => RemittanceFile::visibleTo($u)->pluck('id')->sort()->values()->all();

        // AS: only their own files (public or not); never another specialist's, even public ones
        $this->assertSame([$aPrivate->id, $aPublic->id], $ids($a));
        // User: public files from anyone, no private ones
        $this->assertSame([$aPublic->id, $bPublic->id], $ids($plain));
        $this->assertNotContains($bPrivate->id, $ids($plain));
        // Admin: everything
        $this->assertCount(4, $ids($admin));
    }

    public function test_run_token_is_stored_hashed(): void
    {
        $run = ExtractionRun::factory()->create(['callback_token_hash' => ExtractionRun::hashToken('secret')]);

        $this->assertTrue($run->tokenMatches('secret'));
        $this->assertFalse($run->tokenMatches('other'));
        $this->assertFalse($run->tokenMatches(null));
        $this->assertArrayNotHasKey('callback_token_hash', $run->toArray());
    }
}
