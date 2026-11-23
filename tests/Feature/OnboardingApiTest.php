<?php

namespace Tests\Feature;

use App\Models\Institution;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\PlatformNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OnboardingApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('documents');
        Notification::fake();
    }

    private function staff(string $type = 'admin', array $attrs = []): User
    {
        return User::create($attrs + ['type' => $type, 'name' => 'Staff', 'email' => $type . uniqid() . '@fmdq.test', 'password' => Hash::make('x'), 'status' => 'active']);
    }

    private function bearer(User $user): self
    {
        return $this->withHeader('Authorization', 'Bearer ' . auth('api')->login($user));
    }

    private function tspPayload(array $users): array
    {
        return [
            'name' => 'New Securities', 'email' => 'ops@new.test', 'consent' => true, 'registration_number' => 'FMDQ/TSP/001',
            'proof' => UploadedFile::fake()->create('cert.pdf', 50, 'application/pdf'),
            'users' => $users,
        ];
    }

    private function bothLevels(): array
    {
        return [
            ['name' => 'A', 'email' => 'a@new.test', 'access_level' => 'inputter'],
            ['name' => 'B', 'email' => 'b@new.test', 'access_level' => 'authoriser'],
        ];
    }

    public function test_four_account_types_are_offered(): void
    {
        $this->getJson('/api/onboarding/account-types')->assertOk()->assertJsonCount(4, 'data.accountTypes');
    }

    public function test_tsp_needs_consent(): void
    {
        $payload = $this->tspPayload($this->bothLevels());
        unset($payload['consent']);

        $this->postJson('/api/onboarding/tsp', $payload)->assertStatus(422)->assertJsonValidationErrors('consent');
    }

    public function test_tsp_needs_an_inputter_and_an_authoriser(): void
    {
        $this->postJson('/api/onboarding/tsp', $this->tspPayload([
            ['name' => 'A', 'email' => 'a@new.test', 'access_level' => 'inputter'],
            ['name' => 'B', 'email' => 'b@new.test', 'access_level' => 'inputter'],
        ]))->assertStatus(422)->assertJsonValidationErrors('users');
    }

    public function test_valid_tsp_request_is_pending_until_reviewed(): void
    {
        $this->postJson('/api/onboarding/tsp', $this->tspPayload($this->bothLevels()))->assertOk()->assertJsonPath('data.kind', 'tsp');

        $institution = Institution::where('type', 'tsp')->firstOrFail();
        $this->assertSame(Institution::STATUS_PENDING, $institution->status);
        $this->assertNotNull($institution->privacy_consent_at);
        $this->assertSame(2, $institution->users()->where('status', User::STATUS_PENDING)->count());
    }

    public function test_admin_approval_activates_users_and_links_to_the_vue_app(): void
    {
        $institution = Institution::create(['type' => 'regulator', 'regulator_body' => 'SEC', 'name' => 'SEC', 'email' => 's@sec.test']);
        $reviewer = $institution->users()->create(['type' => 'regulator', 'name' => 'Rev', 'email' => 'rev@sec.test', 'status' => 'pending']);

        $this->bearer($this->staff())->postJson("/api/admin/onboarding/{$institution->id}/approve")->assertOk();

        $this->assertSame(User::STATUS_ACTIVE, $reviewer->fresh()->status);
        Notification::assertSentTo($reviewer, PlatformNotification::class, function ($n) {
            return str_contains((string) $n->url, '/auth/reset-password?token=');
        });
        // The institution's own address (not a user's) is told the outcome too.
        Notification::assertSentOnDemand(PlatformNotification::class, function ($n, $channels, $to) {
            return $to->routes['mail'] === 's@sec.test' && str_contains($n->title, 'approved');
        });
    }

    public function test_rejected_users_are_archived_and_can_register_again(): void
    {
        $this->postJson('/api/onboarding/tsp', $this->tspPayload($this->bothLevels()))->assertOk();
        $institution = Institution::where('type', 'tsp')->firstOrFail();
        $applicant = $institution->users()->firstOrFail();

        $this->bearer($this->staff())
            ->postJson("/api/admin/onboarding/{$institution->id}/reject", ['reason' => 'Proof of registration is unreadable.'])
            ->assertOk();

        // Moved out of users, kept in the archive, and still told why.
        $this->assertSame(Institution::STATUS_REJECTED, $institution->fresh()->status);
        $this->assertDatabaseMissing('users', ['email' => 'a@new.test']);
        $this->assertDatabaseHas('rejected_onboarding_users', ['institution_id' => $institution->id, 'email' => 'a@new.test', 'access_level' => 'inputter']);
        // Addressed on demand: the User rows are gone by the time the queued mail runs.
        Notification::assertSentOnDemand(PlatformNotification::class, function ($n, $channels, $to) use ($applicant) {
            return $to->routes['mail'] === $applicant->email && str_contains($n->message, 'unreadable');
        });
        // The institution's own address is told the outcome too.
        Notification::assertSentOnDemand(PlatformNotification::class, function ($n, $channels, $to) {
            return $to->routes['mail'] === 'ops@new.test' && str_contains($n->title, 'not approved');
        });

        // The admin record still lists the archived users.
        $this->bearer($this->staff())->getJson("/api/admin/onboarding/{$institution->id}")
            ->assertOk()->assertJsonCount(2, 'data.institution.users')->assertJsonPath('data.institution.users.0.status', 'rejected');

        // Same emails register again as a new pending request.
        $this->postJson('/api/onboarding/tsp', $this->tspPayload($this->bothLevels()))->assertOk();
        $this->assertSame(2, User::whereIn('email', ['a@new.test', 'b@new.test'])->where('status', User::STATUS_PENDING)->count());
    }

    public function test_rejected_institution_registration_number_can_be_reused(): void
    {
        Institution::create(['type' => 'qii', 'name' => 'Old QII', 'email' => 'q@old.test', 'registration_number' => 'RC123', 'status' => Institution::STATUS_REJECTED]);

        $this->postJson('/api/onboarding/institutional', [
            'consent' => true, 'category' => 'qii', 'name' => 'New QII', 'registration_number' => 'RC123', 'email' => 'q@new.test',
            'certificate' => UploadedFile::fake()->create('cac.pdf', 50, 'application/pdf'),
            'users' => $this->bothLevels(),
        ])->assertOk();
    }

    public function test_institutional_representatives_have_no_access_level(): void
    {
        // One representative, no Inputter / Authoriser: that split applies to TSPs only.
        $this->postJson('/api/onboarding/institutional', [
            'consent' => true, 'category' => 'non_qii', 'name' => 'Rep Ltd', 'registration_number' => 'RC777', 'email' => 'info@rep.test',
            'certificate' => UploadedFile::fake()->create('cac.pdf', 50, 'application/pdf'),
            'users' => [['name' => 'Ada Rep', 'email' => 'ada@rep.test', 'access_level' => 'authoriser']],
        ])->assertOk();

        $this->assertNull(User::where('email', 'ada@rep.test')->value('access_level'));

        $this->postJson('/api/onboarding/institutional', [
            'consent' => true, 'category' => 'qii', 'name' => 'No Rep Ltd', 'registration_number' => 'RC778', 'email' => 'info@norep.test',
            'certificate' => UploadedFile::fake()->create('cac.pdf', 50, 'application/pdf'),
            'users' => [],
        ])->assertStatus(422)->assertJsonValidationErrors('users');
    }

    public function test_cac_register_checks_rc_number_and_name_and_admin_can_refresh(): void
    {
        config(['services.cac.driver' => 'cac']);
        $company = ['approvedName' => 'CAVIDEL LIMITED', 'rcNumber' => '1235440', 'companyRegistrationDate' => '2015-01-12T01:00:00Z', 'classificationName' => 'COMPANY', 'status' => 'INACTIVE'];
        $none = ['status' => 200, 'data' => [], 'success' => true];
        $found = ['status' => 200, 'data' => [$company], 'success' => true];
        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::sequence()
                ->push($none)->push($none)     // 1: RC search, then name search: nothing
                ->push($none)->push($found)    // 2: RC unknown, name found
                ->push($found)                 // 3: RC found (no name search)
                ->push($found)                 // 4: RC and name found
                ->push(['status' => 200, 'data' => [['status' => 'ACTIVE'] + $company], 'success' => true]),
        ]);
        $n = 0;
        $payload = function (array $over) use (&$n) {
            $n++;

            return $over + [
                'consent' => true, 'category' => 'qii', 'email' => "info{$n}@cavidel.test",
                'certificate' => UploadedFile::fake()->create('cac.pdf', 50, 'application/pdf'),
                'users' => [['name' => 'Ada Rep', 'email' => "ada{$n}@cavidel.test"]],
            ];
        };
        $resultFor = function (string $name) {
            return Institution::where('name', $name)->firstOrFail()->cacLookups()->first()->result;
        };

        // 1. CAC knows neither the RC number nor the name: refused.
        $this->postJson('/api/onboarding/institutional', $payload(['name' => 'Nothing Like It Ltd', 'registration_number' => '9999999']))
            ->assertStatus(422)->assertJsonValidationErrors('registration_number');

        // 2. Name known, RC number not: accepted, flagged for the reviewer.
        $this->postJson('/api/onboarding/institutional', $payload(['name' => 'Cavidel Limited', 'registration_number' => '9999999']))->assertOk();
        $this->assertSame('rc_mismatch', $resultFor('Cavidel Limited'));

        // 3. RC number known, name not: accepted, flagged.
        $this->postJson('/api/onboarding/institutional', $payload(['name' => 'Cavidel Partners', 'registration_number' => 'RC 1235440']))->assertOk();
        $this->assertSame('name_mismatch', $resultFor('Cavidel Partners'));

        // 4. Both match ("Ltd" for "Limited", an "RC" prefix); the search is stored for the admin.
        $this->postJson('/api/onboarding/institutional', $payload(['name' => 'Cavidel Ltd.', 'registration_number' => 'RC1235440']))->assertOk();
        $institution = Institution::where('name', 'Cavidel Ltd.')->firstOrFail();
        $this->assertSame('verified', $resultFor('Cavidel Ltd.'));

        // The admin fetches the latest record: a new search, newest first, now Active.
        $admin = $this->staff();
        $this->bearer($admin)->postJson("/api/admin/onboarding/{$institution->id}/cac")->assertOk()
            ->assertJsonPath('data.cac.0.company.status', 'ACTIVE')
            ->assertJsonPath('data.cac.0.searched_by_name', $admin->name)
            ->assertJsonPath('data.cac.1.searched_by_name', null);
        $this->bearer($admin)->getJson("/api/admin/onboarding/{$institution->id}")->assertOk()->assertJsonCount(2, 'data.cac');
    }

    public function test_onboarding_review_is_admin_only(): void
    {
        $this->bearer($this->staff('investor'))->getJson('/api/admin/onboarding')->assertStatus(403);
    }

    public function test_activation_link_sets_a_password(): void
    {
        $user = $this->staff('investor', ['password' => null, 'email' => 'new@x.test']);
        $token = \Illuminate\Support\Facades\Password::broker()->createToken($user);

        $this->postJson('/api/auth/reset-password', [
            'token' => $token, 'email' => 'new@x.test', 'password' => 'Str0ng!Pass#21', 'password_confirmation' => 'Str0ng!Pass#21',
        ])->assertOk();

        $this->assertTrue(Hash::check('Str0ng!Pass#21', $user->fresh()->password));
    }

    public function test_forgot_password_does_not_reveal_whether_an_account_exists(): void
    {
        $this->staff('investor', ['email' => 'known@x.test']);

        $known = $this->postJson('/api/auth/forgot-password', ['email' => 'known@x.test'])->assertOk()->json('message');
        $unknown = $this->postJson('/api/auth/forgot-password', ['email' => 'nobody@x.test'])->assertOk()->json('message');

        $this->assertSame($known, $unknown);
    }

    public function test_retail_needs_a_verified_bvn_token(): void
    {
        $body = ['email' => 'ife@x.test', 'phone' => '0803', 'terms' => true];

        $this->postJson('/api/onboarding/retail', $body + ['verification' => 'forged'])->assertStatus(422)->assertJsonValidationErrors('verification');

        $stale = Crypt::encrypt(['record' => ['first_name' => 'I', 'last_name' => 'O', 'bvn' => '22212345678'], 'at' => now()->subHour()->getTimestamp()]);
        $this->postJson('/api/onboarding/retail', $body + ['verification' => $stale])->assertStatus(422)->assertJsonValidationErrors('verification');
    }

    public function test_retail_onboarding_completes_and_never_returns_the_full_bvn(): void
    {
        $verified = $this->postJson('/api/onboarding/retail/verify', ['consent' => true, 'bvn' => '22212345678', 'first_name' => 'Ife', 'last_name' => 'Ola'])->assertOk();
        $this->assertStringNotContainsString('22212345678', json_encode($verified->json('data.verified')));

        $this->postJson('/api/onboarding/retail', [
            'verification' => $verified->json('data.verification'), 'email' => 'ife@x.test', 'phone' => '0803', 'terms' => true,
        ])->assertOk();

        $this->assertDatabaseHas('users', ['email' => 'ife@x.test', 'type' => 'investor']);
    }

    private function approvedTsp(): User
    {
        $institution = Institution::create(['type' => 'tsp', 'name' => 'Sponsor', 'email' => 't@s.test', 'status' => 'approved']);

        return $institution->users()->create(['type' => 'tsp', 'access_level' => 'inputter', 'name' => 'In', 'email' => 'in@s.test', 'status' => 'active']);
    }

    public function test_fee_disabled_registration_gives_full_access(): void
    {
        Setting::put('platform_fee_enabled', false);
        $user = $this->approvedTsp();

        $this->bearer($user)->getJson('/api/tsp/access-check')->assertStatus(403);

        $this->putJson('/api/tsp/registration', [
            'registered_name' => 'Sponsor Ltd', 'registered_address' => '1 Road', 'contact_name' => 'C', 'contact_role' => 'Ops', 'contact_phone' => '0803',
            'bank_name' => 'Zenith Bank', 'bank_account_name' => 'Sponsor Ltd', 'bank_account_number' => '1029384756',
        ])->assertOk();

        $this->getJson('/api/tsp/access-check')->assertOk();
    }

    public function test_tsp_cannot_touch_another_institutions_invoice(): void
    {
        $other = Institution::create(['type' => 'tsp', 'name' => 'Other', 'email' => 'o@s.test', 'status' => 'approved']);
        $invoice = $other->feeInvoices()->create([
            'reference' => 'FEE-1', 'period_start' => now(), 'period_end' => now()->addYear(), 'due_date' => now()->addMonth(), 'amount' => 100,
        ]);

        $this->bearer($this->approvedTsp())->getJson("/api/tsp/registration/fee/{$invoice->id}/receipt")->assertStatus(403);
    }
}
