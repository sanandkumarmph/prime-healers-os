<?php

namespace App\Services\Auth;

use App\Models\User;

class PasswordRecovery
{
    public const RECEIVED = 'Password-reset request received. If the account is eligible, check its inbox for a reset link. If it does not arrive, contact your administrator.';
    public const UNAVAILABLE = 'Password reset email is not configured yet. Contact your PHOS administrator for help.';

    public function available(): bool
    {
        $from = (string) config('mail.from.address', '');
        $domain = strtolower(substr(strrchr($from, '@') ?: '', 1));

        return $this->baseUrl() !== null
            && filter_var($from, FILTER_VALIDATE_EMAIL) !== false
            && !in_array($domain, ['example.com', 'example.org', 'example.net', 'localhost'], true)
            && $this->deliveringMailer((string) config('mail.default', ''), []);
    }

    public function eligibleUser(string $email): ?User
    {
        $users = User::query()->whereRaw('LOWER(email) = ?', [strtolower(trim($email))])->limit(2)->get();
        $user = $users->count() === 1 ? $users->first() : null;

        return $user?->hasActiveAccess() ? $user : null;
    }

    public function resetUrl(User $user, string $token): string
    {
        $base = $this->baseUrl();
        if ($base === null) {
            throw new \LogicException('Password recovery requires a valid configured application URL.');
        }

        return $base.'/reset-password/'.rawurlencode($token).'?'.http_build_query([
            'email' => $user->getEmailForPasswordReset(),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    private function baseUrl(): ?string
    {
        $base = rtrim((string) config('app.url', ''), '/');
        $parts = parse_url($base);
        if (!$parts || empty($parts['host']) || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        if (!app()->environment(['local', 'testing']) && $parts['scheme'] !== 'https') {
            return null;
        }

        return $base;
    }

    private function deliveringMailer(string $name, array $visited): bool
    {
        if ($name === '' || in_array($name, $visited, true)) {
            return false;
        }
        $visited[] = $name;
        $mailer = config('mail.mailers.'.$name, []);
        $transport = $mailer['transport'] ?? '';
        if (in_array($transport, ['log', 'array', ''], true)) {
            return false;
        }
        if (in_array($transport, ['failover', 'roundrobin'], true)) {
            $children = $mailer['mailers'] ?? [];
            return count($children) > 0 && collect($children)->every(fn ($child) => $this->deliveringMailer($child, $visited));
        }
        if ($transport === 'smtp') {
            if (!empty($mailer['url'])) {
                $url = parse_url($mailer['url']);
                return $url && !empty($url['host']) && in_array($url['scheme'] ?? '', ['smtp', 'smtps'], true);
            }
            $host = strtolower(trim((string) ($mailer['host'] ?? '')));
            $port = (int) ($mailer['port'] ?? 0);
            return $host !== '' && $port > 0 && $port <= 65535
                && !(in_array($host, ['127.0.0.1', 'localhost', '::1'], true) && $port === 2525)
                && (filled($mailer['username'] ?? null) === filled($mailer['password'] ?? null));
        }

        return match ($transport) {
            'sendmail' => filled($mailer['path'] ?? null),
            'ses', 'ses-v2' => filled(config('services.ses.key')) && filled(config('services.ses.secret')),
            'postmark' => filled(config('services.postmark.token')),
            'resend' => filled(config('services.resend.key')),
            'mailgun' => filled(config('services.mailgun.domain')) && filled(config('services.mailgun.secret')),
            default => false,
        };
    }
}
