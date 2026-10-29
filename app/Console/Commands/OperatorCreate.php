<?php

namespace App\Console\Commands;

use App\Models\Operator;
use App\Models\OperatorAudit;
use App\Tenancy\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/** Hosted service only: adds someone who can sign in to the operator console (two-factor is set up at first sign-in). */
class OperatorCreate extends Command
{
    protected $signature = 'operator:create {--name=} {--email=}';

    protected $description = '[Hosted service only] Add an operator console account';

    public function handle(Tenancy $tenancy): int
    {
        if (! $tenancy->enabled()) {
            $this->error('The operator console is for the hosted service (QUAVERDESK_MULTI_TENANT).');

            return self::FAILURE;
        }

        $data = [
            'name' => $this->option('name') ?: $this->ask('Name'),
            'email' => strtolower((string) ($this->option('email') ?: $this->ask('Email'))),
            'password' => $this->secret('Password (16+ characters)'),
        ];
        $validator = Validator::make($data, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:operators,email'],
            'password' => ['required', 'string', Password::min(16)->letters()->numbers()],
        ]);
        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::INVALID;
        }

        Operator::query()->create($data);
        OperatorAudit::record('operator.created', details: ['email' => $data['email']]);
        $this->info("Operator {$data['email']} added. They set up two-factor the first time they sign in at https://".config('tenancy.operator_host').'/operator');

        return self::SUCCESS;
    }
}
