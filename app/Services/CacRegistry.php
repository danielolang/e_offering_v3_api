<?php

namespace App\Services;

use App\Models\CacLookup;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Checks a QII / Non-QII institution's RC number and registered name against the CAC public
 * search (the endpoint behind icrp.cac.gov.ng/public-search). Each search is stored with its
 * time and the full response, so the FMDQ reviewer sees what CAC said and can fetch it again.
 *
 * The institution passes if CAC knows its RC number OR its name: the search is by RC number
 * first and, if CAC has no company with that number, by name.
 *
 * Results: verified (RC and name match), name_mismatch (RC found under another name),
 * rc_mismatch (name found under another RC number), not_found (neither found: blocks
 * onboarding), unavailable (CAC could not be reached).
 */
class CacRegistry
{
    public function enabled(): bool
    {
        return config('services.cac.driver') === 'cac';
    }

    /**
     * Searches CAC without saving.
     *
     * @return array{rc_number: string, name: string, result: string, company: ?array, response: ?array, error: ?string, searched_at: \Illuminate\Support\Carbon}
     */
    public function search(string $rcNumber, string $name): array
    {
        $check = [
            'rc_number' => trim($rcNumber), 'name' => trim($name), 'result' => CacLookup::UNAVAILABLE,
            'company' => null, 'response' => null, 'error' => null, 'searched_at' => now(),
        ];
        $wantedRc = self::normaliseRc($rcNumber);
        $wantedName = self::normaliseName($name);
        $sameRc = function (array $entry) use ($wantedRc) {
            return $wantedRc !== '' && self::normaliseRc((string) ($entry['rcNumber'] ?? '')) === $wantedRc;
        };
        $sameName = function (array $entry) use ($wantedName) {
            return $wantedName !== '' && self::normaliseName((string) ($entry['approvedName'] ?? '')) === $wantedName;
        };

        // 1. By RC number.
        $entries = $wantedRc !== '' ? $this->query($wantedRc, $check) : [];
        if ($entries === null) {
            return $check;
        }
        $company = self::first($entries, $sameRc);

        // 2. Not found by RC number: by name.
        if (! $company && $wantedName !== '') {
            $byName = $this->query(trim($name), $check);
            if ($byName === null) {
                return $check;
            }
            $entries = array_merge($entries, $byName);
            // Prefer an entry matching both, then one matching the name.
            $company = self::first($byName, function ($e) use ($sameRc, $sameName) {
                return $sameRc($e) && $sameName($e);
            }) ?? self::first($byName, $sameName);
        }

        $check['response'] = $entries;
        $check['company'] = $company;

        if (! $company) {
            $check['result'] = CacLookup::NOT_FOUND;
        } elseif ($sameRc($company) && $sameName($company)) {
            $check['result'] = CacLookup::VERIFIED;
        } elseif ($sameRc($company)) {
            $check['result'] = CacLookup::NAME_MISMATCH;
        } else {
            $check['result'] = CacLookup::RC_MISMATCH;
        }

        return $check;
    }

    /**
     * One CAC search. Returns the entries, or null after setting $check's error (unavailable).
     */
    private function query(string $term, array &$check): ?array
    {
        try {
            // The CAC API answers only its own site's origin; it allows 5 searches per window.
            $response = Http::timeout((int) config('services.cac.timeout', 15))
                ->when(config('services.cac.ca_bundle'), function ($http, $bundle) {
                    return $http->withOptions(['verify' => $bundle]);
                })
                ->acceptJson()->asJson()
                ->withHeaders(['Origin' => 'https://icrp.cac.gov.ng', 'Referer' => 'https://icrp.cac.gov.ng/'])
                ->post((string) config('services.cac.url'), ['SearchType' => 'ALL', 'searchTerm' => $term]);
        } catch (Throwable $e) {
            Log::warning('CAC search failed: '.$e->getMessage());
            $check['error'] = 'The CAC registry could not be reached.';

            return null;
        }

        if ($response->failed() || ! is_array($response->json('data'))) {
            Log::warning('CAC search returned HTTP '.$response->status());
            $check['error'] = $response->status() === 429
                ? 'The CAC registry is busy (too many searches). Try again in a minute.'
                : 'The CAC registry returned an unexpected response (HTTP '.$response->status().').';

            return null;
        }

        return array_values(array_filter($response->json('data'), 'is_array'));
    }

    private static function first(array $entries, callable $match): ?array
    {
        foreach ($entries as $entry) {
            if ($match($entry)) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Onboarding: refuse only when CAC knows neither the RC number nor the name. A match on one
     * of them goes through, flagged for the reviewer; so does a request when CAC is unreachable.
     */
    public function assertValid(array $check): void
    {
        if ($check['result'] === CacLookup::NOT_FOUND) {
            throw ValidationException::withMessages([
                'registration_number' => 'Neither this RC number nor the institution name was found on the CAC register. Check them against your Certificate of Incorporation.',
            ]);
        }
    }

    public function record(Institution $institution, array $check, ?User $by = null): CacLookup
    {
        return $institution->cacLookups()->create([
            'rc_number' => $check['rc_number'],
            'name' => $check['name'],
            'result' => $check['result'],
            'company' => $check['company'],
            'response' => $check['response'],
            'error' => $check['error'],
            'searched_by' => optional($by)->id,
            'searched_at' => $check['searched_at'],
        ]);
    }

    /** Admin "Fetch Latest from CAC": searches again with the institution's current details. */
    public function refresh(Institution $institution, User $by): CacLookup
    {
        return $this->record($institution, $this->search((string) $institution->registration_number, (string) $institution->name), $by);
    }

    /** "RC 1235440", "rc-1235440" and "1235440" are the same; other prefixes (BN, KD, IT) stay. */
    public static function normaliseRc(string $rc): string
    {
        $rc = strtoupper(preg_replace('/[\s.\-\/]+/', '', $rc));

        return preg_replace('/^RC(?=\d)/', '', $rc);
    }

    /** Case, punctuation, "&"/"and" and "Ltd"/"Limited"-style abbreviations do not count as a mismatch. */
    public static function normaliseName(string $name): string
    {
        $name = strtoupper($name);
        $name = str_replace('&', ' AND ', $name);
        $name = preg_replace('/[^A-Z0-9 ]+/', ' ', $name);
        $words = preg_split('/\s+/', trim($name)) ?: [];
        $aliases = ['LTD' => 'LIMITED', 'CO' => 'COMPANY', 'NIG' => 'NIGERIA', 'INTL' => 'INTERNATIONAL'];
        $words = array_map(function ($w) use ($aliases) {
            return $aliases[$w] ?? $w;
        }, $words);

        return str_replace('PUBLIC LIMITED COMPANY', 'PLC', implode(' ', $words));
    }
}
