for ($i = 1; $i <= 150; $i++) {
    $u = App\Models\User::create(['name' => "Trader $i", 'alias' => "load_$i", 'mobile' => sprintf('05%08d', 90000000 + $i), 'password' => 'secret-pass-1']);
    App\Services\Ledger::grantStartingBalance($u->id);
    echo 'TOK '.$u->id.' '.$u->createToken('api')->plainTextToken."\n";
}
