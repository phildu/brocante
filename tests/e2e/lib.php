<?php

// Socle des tests e2e : client HTTP (curl + jar de cookies), extraction de
// formulaires, assertions, accès direct à la base du workspace, webhooks signés.

final class E2e
{
    public string $appUrl = 'http://127.0.0.1:8099';
    public string $stripeUrl = 'http://127.0.0.1:8091';
    public string $workspace;

    /** @var array<string, string> jar de cookies par client */
    private array $jars = [];
    /** @var array<string, array<int, array{step: string, state: string, detail: string}>> */
    public array $results = [];
    private string $current = '';

    public function __construct(string $workspace)
    {
        $this->workspace = $workspace;
    }

    public function scenario(string $name, callable $fn): void
    {
        $this->current = $name;
        $this->results[$name] = [];
        echo "\n=== $name ===\n";
        try {
            $fn($this);
        } catch (Throwable $e) {
            $this->record('scénario', 'FAIL', $e->getMessage() . ' (' . basename($e->getFile()) . ':' . $e->getLine() . ')');
        }
    }

    public function step(string $label, callable $fn): void
    {
        try {
            $detail = (string) $fn();
            $this->record($label, 'PASS', $detail);
        } catch (Throwable $e) {
            $this->record($label, 'FAIL', $e->getMessage());
        }
    }

    public function skip(string $label, string $reason): void
    {
        $this->record($label, 'SKIP', $reason);
    }

    private function record(string $step, string $state, string $detail): void
    {
        $this->results[$this->current][] = ['step' => $step, 'state' => $state, 'detail' => $detail];
        $mark = ['PASS' => ' ok ', 'FAIL' => 'FAIL', 'SKIP' => 'skip'][$state];
        printf("  [%s] %s%s\n", $mark, $step, $detail !== '' && $state !== 'PASS' ? ' — ' . $detail : ($detail !== '' && $state === 'PASS' ? ' — ' . $detail : ''));
    }

    public function check(bool $cond, string $message): void
    {
        if (!$cond) throw new RuntimeException($message);
    }

    /**
     * Requête HTTP. $opts : data (POST), headers, nofollow (renvoie la 302 sans suivre).
     * Retourne ['status' => int, 'headers' => string, 'body' => string, 'location' => string].
     */
    public function http(string $method, string $url, array $opts = [], string $client = 'default'): array
    {
        $jar = sys_get_temp_dir() . '/brocs-e2e-cookies-' . getmypid() . '-' . $client . '.txt';
        $this->jars[$client] = $jar;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_COOKIEFILE => $jar,
            CURLOPT_COOKIEJAR => $jar,
            CURLOPT_FOLLOWLOCATION => empty($opts['nofollow']),
            CURLOPT_MAXREDIRS => empty($opts['nofollow']) ? 5 : 0,
        ]);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $opts['data'] ?? '');
        }
        if (!empty($opts['header_lines'])) {
            curl_setopt($ch, CURLOPT_HTTPHEADER, $opts['header_lines']);
        }
        $raw = (string) curl_exec($ch);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        $headers = substr($raw, 0, $headerSize);
        $body = substr($raw, $headerSize);
        $location = '';
        if (preg_match('/^Location:\s*(.+)$/mi', $headers, $m)) $location = trim($m[1]);
        $out = [
            'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'headers' => $headers,
            'body' => $body,
            'location' => $location,
        ];
        return $out;
    }

    public function get(string $path, string $client = 'default', bool $nofollow = false): array
    {
        return $this->http('GET', $this->appUrl . $path, ['nofollow' => $nofollow], $client);
    }

    public function post(string $path, array $data, string $client = 'default', bool $nofollow = false): array
    {
        return $this->http('POST', $this->appUrl . $path, ['data' => $data, 'nofollow' => $nofollow], $client);
    }

    /** Extrait les champs cachus + la valeur d'un champ nommé du premier formulaire dont l'action contient $actionPart. */
    public function formFields(string $html, string $actionPart = ''): array
    {
        if (!preg_match_all('#<form[^>]*>(.*?)</form>#is', $html, $forms)) return [];
        $form = $forms[1][0];
        foreach ($forms[0] as $i => $full) {
            if ($actionPart !== '' && stripos($full, $actionPart) !== false) { $form = $forms[1][$i]; break; }
        }
        $fields = [];
        if (preg_match_all('#<input([^>]+)>#i', $form, $inputs)) {
            foreach ($inputs[1] as $attrs) {
                if (!preg_match('#name="([^"]+)"#', $attrs, $nm)) continue;
                $val = preg_match('#value="([^"]*)"#', $attrs, $v) ? html_entity_decode($v[1], ENT_QUOTES, 'UTF-8') : '';
                if (preg_match('#type="(radio|checkbox|submit|button)"#i', $attrs) && !str_contains($attrs, 'checked')) continue;
                $name = $nm[1];
                $fields[$name] = $val;
            }
        }
        return $fields;
    }

    /** Valeur du jeton csrf d'une page (champ hidden name="csrf"). */
    public function csrfFrom(string $html): string
    {
        $fields = $this->formFields($html);
        return (string) ($fields['csrf'] ?? '');
    }

    public function db(string $slug): PDO
    {
        return new PDO('sqlite:' . $this->workspace . '/app/data/' . $slug . '.db', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    /** Envoie un webhook Stripe signé à /inscription/webhook.php. $object : le contenu de data.object. */
    public function sendWebhook(string $type, array $object): array
    {
        $secrets = json_decode((string) file_get_contents($this->workspace . '/app/.secrets/stripe_plateforme.json'), true);
        $whsec = (string) ($secrets['webhook_secret'] ?? '');
        $payload = json_encode(['type' => $type, 'data' => ['object' => $object]], JSON_UNESCAPED_SLASHES);
        $t = time();
        $sig = hash_hmac('sha256', $t . '.' . $payload, $whsec);
        return $this->http('POST', $this->appUrl . '/inscription/webhook.php', [
            'data' => $payload,
            'headers' => [],
            'raw' => true,
            'header_lines' => ["Stripe-Signature: t=$t,v1=$sig", 'Content-Type: application/json'],
        ]);
    }

    /** Appel direct au faux Stripe (y compris la route de test /v1/test/…). $data : champs ou corps JSON brut. */
    public function stripe(string $method, string $path, array|string $data = []): array
    {
        $ch = curl_init($this->stripeUrl . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_USERPWD => 'sk_test_e2e00000000000000000000000000:']);
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($data) ? http_build_query($data, '', '&') : $data);
        }
        $body = (string) curl_exec($ch);
        return json_decode($body, true) ?? ['_raw' => $body];
    }

    public function summary(): array
    {
        $counts = ['PASS' => 0, 'FAIL' => 0, 'SKIP' => 0];
        foreach ($this->results as $steps) foreach ($steps as $s) $counts[$s['state']]++;
        return $counts;
    }
}
