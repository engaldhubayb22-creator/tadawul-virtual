<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = "symbol,name,sector,issued_shares,free_float_shares,price,equity,net_income\n";

    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($path, self::HEADER.$body);

        return $path;
    }

    private function bots(): array
    {
        $ids = [];
        foreach (['mm-bot', 'noise-bot'] as $i => $alias) {
            $ids[] = DB::table('users')->insertGetId(['name' => $alias, 'alias' => $alias, 'mobile' => $alias, 'password' => '!', 'role' => 'bot']);
        }

        return $ids;
    }

    private function mismatches(): int
    {
        return (int) DB::selectOne('SELECT count(*) AS n FROM companies c WHERE c.shares_outstanding - c.founder_shares
            <> COALESCE((SELECT sum(qty) FROM positions p WHERE p.company_id = c.id), 0)')->n;
    }

    public function test_import_places_the_free_float_with_the_funds(): void
    {
        [$maker, $noise] = $this->bots();
        $file = $this->csv("1010,Test Bank,Banks,\"3,000,000\",1500000,28.40,45000000,6000000\n6013,Small Co,Food,300000,300000,81.00,,\n");
        $this->artisan('market:import', ['file' => $file])->assertSuccessful();

        $bank = DB::table('companies')->where('symbol', '1010')->first();
        $this->assertSame([3000000, 1500000, 2840, 2, 4500000000, 600000000, 'trading'],
            [(int) $bank->shares_outstanding, (int) $bank->founder_shares, (int) $bank->ref_price, (int) $bank->tick_size,
                (int) $bank->equity, (int) $bank->net_income, $bank->status]);
        // 5% of the float (75,000) to the trading fund, the rest to the liquidity fund.
        $this->assertSame(1425000, (int) DB::table('positions')->where(['user_id' => $maker, 'company_id' => $bank->id])->value('qty'));
        $this->assertSame(75000, (int) DB::table('positions')->where(['user_id' => $noise, 'company_id' => $bank->id])->value('qty'));
        // A 100% float keeps one share with the founders.
        $small = DB::table('companies')->where('symbol', '6013')->first();
        $this->assertSame(1, (int) $small->founder_shares);
        $this->assertSame(5, (int) $small->tick_size);
        $this->assertSame(0, $this->mismatches());

        // Importing again with a larger and a smaller float moves the liquidity fund's holding.
        DB::table('companies')->where('symbol', '1010')->update(['ref_price' => 3000]);
        $file = $this->csv("1010,Test Bank,Banks,3300000,1800000,10.00,,\n6013,Small Co,Food,300000,200000,81.00,,\n");
        $this->artisan('market:import', ['file' => $file])->assertSuccessful();
        $bank = DB::table('companies')->where('symbol', '1010')->first();
        $this->assertSame([3300000, 1500000, 3000, 4500000000], [(int) $bank->shares_outstanding, (int) $bank->founder_shares, (int) $bank->ref_price, (int) $bank->equity]);
        $this->assertSame(1725000, (int) DB::table('positions')->where(['user_id' => $maker, 'company_id' => $bank->id])->value('qty'));
        $this->assertSame(0, $this->mismatches());
    }

    public function test_bad_files_change_nothing(): void
    {
        $this->bots();
        foreach ([
            "1010,Bank,Banks,1000,2000,10.00,,\n",          // float above issued
            "101,Bank,Banks,1000,500,10.00,,\n",            // bad symbol
            "1010,Bank,Banks,1000,500,ten,,\n",             // bad price
            "1010,Bank,Banks,1000,500,10.00,,\n1010,Bank,Banks,1000,500,10.00,,\n", // duplicate
        ] as $body) {
            $this->artisan('market:import', ['file' => $this->csv("2222,Good,Energy,1000,500,10.00,,\n".$body)])->assertFailed();
            $this->assertSame(0, DB::table('companies')->count());
        }
        // A float cut larger than what the liquidity fund still holds is refused whole.
        $this->artisan('market:import', ['file' => $this->csv("2222,Good,Energy,1000,500,10.00,,\n")])->assertSuccessful();
        DB::table('positions')->where('user_id', DB::table('users')->where('alias', 'mm-bot')->value('id'))->update(['reserved_qty' => 400]);
        $this->artisan('market:import', ['file' => $this->csv("3333,New,Energy,1000,500,10.00,,\n2222,Good,Energy,1000,300,10.00,,\n")])->assertFailed();
        $this->assertSame(1, DB::table('companies')->count());
        $this->artisan('market:import', ['file' => $this->csv("3333,New,Energy,1000,500,10.00,,\n"), '--dry-run' => true])->assertSuccessful();
        $this->assertSame(1, DB::table('companies')->count());
    }
}
