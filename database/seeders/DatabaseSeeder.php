<?php

namespace Database\Seeders;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Demo accounts, one per role. Every password is "Pass@1111"; set OTP_BYPASS=true
 * (or read the code from storage/logs with MAIL_MAILER=log) to sign in locally.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $password = Hash::make('Pass@1111');
        $now = now();

        $user = function (array $attributes) use ($password, $now) {
            return User::updateOrCreate(['email' => $attributes['email']], array_merge([
                'password' => $password,
                'status' => User::STATUS_ACTIVE,
                'email_verified_at' => $now,
            ], $attributes));
        };

        $user(['name' => 'FMDQ Admin', 'email' => 'admin@fmdqgroup.com', 'type' => User::TYPE_ADMIN, 'access_level' => User::INPUTTER]);
        // Staff account changes raised by an Inputter need an Authoriser to approve them.
        $user(['name' => 'FMDQ Admin Two', 'email' => 'admin2@fmdqgroup.com', 'type' => User::TYPE_ADMIN, 'access_level' => User::AUTHORISER]);
        $user(['name' => 'FMDQ Depository', 'email' => 'csd@fmdqgroup.com', 'type' => User::TYPE_CSD, 'access_level' => User::INPUTTER]);

        $tsp = Institution::updateOrCreate(['email' => 'tsp@fmdqgroup.com'], [
            'type' => Institution::TYPE_TSP,
            'name' => 'Demo Securities Limited',
            'status' => Institution::STATUS_APPROVED,
            'registered_name' => 'Demo Securities Limited',
            'registered_address' => '35 Idowu Taylor Street, Victoria Island, Lagos',
            'contact_name' => 'Ada Obi',
            'contact_role' => 'Head, Capital Markets',
            'contact_phone' => '+234 800 000 0000',
            'registration_completed_at' => $now,
            'bank_name' => 'Demo Bank Plc',
            'bank_account_name' => 'Demo Securities Ltd - Offers',
            'bank_account_number' => '0123456789',
            'reviewed_at' => $now,
            'privacy_consent_at' => $now,
        ]);
        $user(['name' => 'Tola Inputter', 'email' => 'inputter@fmdqgroup.com', 'type' => User::TYPE_TSP, 'access_level' => User::INPUTTER, 'institution_id' => $tsp->id]);
        $user(['name' => 'Bayo Authoriser', 'email' => 'authoriser@fmdqgroup.com', 'type' => User::TYPE_TSP, 'access_level' => User::AUTHORISER, 'institution_id' => $tsp->id]);

        $sec = Institution::updateOrCreate(['email' => 'sec@fmdqgroup.com'], [
            'type' => Institution::TYPE_REGULATOR, 'regulator_body' => 'SEC', 'name' => 'Securities and Exchange Commission',
            'status' => Institution::STATUS_APPROVED, 'contact_name' => 'SEC Reviewer', 'reviewed_at' => $now, 'privacy_consent_at' => $now,
        ]);
        $user(['name' => 'SEC Reviewer', 'email' => 'sec@fmdqgroup.com', 'type' => User::TYPE_REGULATOR, 'institution_id' => $sec->id]);

        $qii = Institution::updateOrCreate(['email' => 'qii@fmdqgroup.com'], [
            'type' => Institution::TYPE_QII, 'name' => 'Demo Pension Fund Administrator', 'registration_number' => 'RC000001',
            'status' => Institution::STATUS_APPROVED, 'reviewed_at' => $now, 'privacy_consent_at' => $now,
        ]);
        $user(['name' => 'Kemi QII', 'email' => 'qii@fmdqgroup.com', 'type' => User::TYPE_INVESTOR, 'access_level' => User::INPUTTER, 'institution_id' => $qii->id]);

        $retail = $user(['name' => 'Chidi Retail', 'email' => 'retail@fmdqgroup.com', 'type' => User::TYPE_INVESTOR]);
        $retail->investorProfile()->updateOrCreate(['user_id' => $retail->id], [
            'category' => 'retail', 'bvn' => '22200000001', 'csd_name' => 'CSCS', 'csd_account_number' => 'C0000001',
            'bank_name' => 'Demo Bank Plc', 'bank_account_name' => 'Chidi Retail', 'bank_account_number' => '0987654321',
            'bvn_verified_at' => $now,
        ]);
    }
}
