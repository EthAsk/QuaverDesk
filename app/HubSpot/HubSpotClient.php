<?php

namespace App\HubSpot;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** The few HubSpot CRM calls we use, with the company's own private app token. */
class HubSpotClient
{
    private const BASE = 'https://api.hubapi.com';

    /** HubSpot's own association: a call to a contact. */
    private const CALL_TO_CONTACT = 194;

    private int $timeout = 15;

    public function __construct(private readonly string $token)
    {
    }

    /** Proves the token works and can read contacts. */
    public function check(): void
    {
        $this->check_(self::http()->get('/crm/v3/objects/contacts', ['limit' => 1]), 'read contacts');
    }

    /** The contact whose phone or mobile number this is, or null. */
    public function findContact(string $e164): ?string
    {
        return $this->searchContact($e164)['id'] ?? null;
    }

    /** What to show for whoever is calling: "Sam Taylor (Hilltop Builders)", or null if HubSpot doesn't know them. */
    public function contactName(string $e164): ?string
    {
        $p = $this->searchContact($e164)['properties'] ?? null;
        if ($p === null) {
            return null;
        }
        $name = trim(($p['firstname'] ?? '').' '.($p['lastname'] ?? ''));
        $company = trim((string) ($p['company'] ?? ''));

        return match (true) {
            $name !== '' && $company !== '' => "{$name} ({$company})",
            $name !== '' => $name,
            default => $company !== '' ? $company : null,
        };
    }

    /** @return array{id: string, properties: array<string, ?string>}|null */
    private function searchContact(string $e164): ?array
    {
        $digits = ltrim($e164, '+');
        // HubSpot keeps numbers as typed ("07700 900123"), so also match on the digits without the country code.
        $national = preg_replace('/^(44|1)/', '', $digits);
        $filters = [
            ['filters' => [['propertyName' => 'phone', 'operator' => 'EQ', 'value' => $e164]]],
            ['filters' => [['propertyName' => 'mobilephone', 'operator' => 'EQ', 'value' => $e164]]],
            ['filters' => [['propertyName' => 'hs_searchable_calculated_phone_number', 'operator' => 'CONTAINS_TOKEN', 'value' => '*'.$national]]],
        ];
        $response = $this->check_($this->http()->post('/crm/v3/objects/contacts/search', [
            'filterGroups' => $filters, 'properties' => ['firstname', 'lastname', 'company'], 'limit' => 1,
        ]), 'search contacts');

        $hit = $response->json('results.0');

        return $hit ? ['id' => (string) $hit['id'], 'properties' => $hit['properties'] ?? []] : null;
    }

    public function createContact(string $e164): string
    {
        return (string) $this->check_($this->http()->post('/crm/v3/objects/contacts', ['properties' => ['phone' => $e164, 'lastname' => $e164]]), 'create contacts')->json('id');
    }

    /** @param array<string, mixed> $properties */
    public function createCall(array $properties, string $contactId): string
    {
        $response = $this->check_($this->http()->post('/crm/v3/objects/calls', [
            'properties' => $properties,
            'associations' => [[
                'to' => ['id' => $contactId],
                'types' => [['associationCategory' => 'HUBSPOT_DEFINED', 'associationTypeId' => self::CALL_TO_CONTACT]],
            ]],
        ]), 'create calls');

        return (string) $response->json('id');
    }

    /** @param array<string, mixed> $properties */
    public function updateCall(string $id, array $properties): void
    {
        $this->check_($this->http()->patch("/crm/v3/objects/calls/{$id}", ['properties' => $properties]), 'update calls');
    }

    /** Seconds to wait; a caller is waiting on the answer, so it is kept short when looking up a name. */
    public function quickly(): static
    {
        $this->timeout = 2;

        return $this;
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(self::BASE)->withToken($this->token)->acceptJson()->timeout($this->timeout)->connectTimeout(min(5, $this->timeout))->withoutRedirecting();
    }

    private function check_(Response $response, string $what): Response
    {
        if ($response->successful()) {
            return $response;
        }
        $hint = match ($response->status()) {
            401 => 'HubSpot refused the token. Check it, and that the private app is still active.',
            403 => "The private app isn't allowed to {$what}: add the crm.objects.contacts and crm.objects.calls read and write scopes.",
            429 => 'HubSpot says to slow down.',
            default => 'HubSpot answered '.$response->status().': '.substr((string) $response->json('message', ''), 0, 150),
        };

        throw new HubSpotError($hint, $response->status());
    }
}
