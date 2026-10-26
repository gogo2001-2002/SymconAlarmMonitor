<?php

declare(strict_types=1);

class MessageCenter extends IPSModule
{
    private const IDENT_MESSAGES = 'Messages';
    private const DEFAULT_RETENTION_DAYS = 30;
    private const MAX_RETENTION_DAYS = 3650;

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyInteger('RetentionDays', self::DEFAULT_RETENTION_DAYS);
        $this->RegisterVariableString(self::IDENT_MESSAGES, 'Nachrichten', '', 10);
        $this->SetValue(self::IDENT_MESSAGES, '[]');
        $this->RegisterTimer('Cleanup', 86400000, "MSG_Cleanup(\$_IPS['TARGET']);");
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->SetTimerInterval('Cleanup', 86400000);
        $this->Cleanup();
    }

    public function GetConfigurationForm()
    {
        $path = __DIR__ . '/form.json';
        $form = @file_get_contents($path);
        return is_string($form) ? $form : '{"elements":[],"actions":[]}';
    }

    /**
     * Public module action for scripts and visualizations.
     * Call with IPS_RequestAction($instanceID, 'AddMessage', $text).
     */
    public function RequestAction($Ident, $Value)
    {
        switch ((string)$Ident) {
            case 'AddMessage':
                $this->AddMessageText((string)$Value);
                return;

            case 'AcknowledgeMessage':
                $this->AcknowledgeMessage((string)$Value);
                return;

            case 'Cleanup':
                $this->Cleanup();
                return;

            default:
                throw new InvalidArgumentException('Unbekannte Nachrichtenaktion: ' . (string)$Ident);
        }
    }

    /**
     * Timer and configuration action entry point.
     */
    public function Cleanup()
    {
        $messages = $this->ReadMessages();
        $retentionDays = max(1, min(self::MAX_RETENTION_DAYS, $this->ReadPropertyInteger('RetentionDays')));
        $expiration = time() - ($retentionDays * 86400);

        $remaining = array_values(array_filter(
            $messages,
            static function (array $message) use ($expiration): bool {
                $createdAt = (int)($message['createdAt'] ?? 0);
                $acknowledged = (bool)($message['acknowledged'] ?? false);

                // Offene Nachrichten bleiben immer erhalten. Nur quittierte Nachrichten
                // werden nach der Frist ab Erstellung gelöscht.
                return !$acknowledged || $createdAt > $expiration;
            }
        ));

        if (count($remaining) !== count($messages)) {
            $this->WriteMessages($remaining);
        }
    }

    private function AddMessageText(string $text): void
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        if (trim($text) === '') {
            throw new InvalidArgumentException('Der Nachrichtentext darf nicht leer sein.');
        }

        $messages = $this->ReadMessages();
        $messages[] = [
            'id' => bin2hex(random_bytes(12)),
            'text' => $text,
            'createdAt' => time(),
            'acknowledged' => false,
            'acknowledgedAt' => null
        ];

        $this->WriteMessages($messages);
    }

    private function AcknowledgeMessage(string $messageId): void
    {
        $messageId = trim($messageId);
        if ($messageId === '') {
            throw new InvalidArgumentException('Die Nachrichten-ID fehlt.');
        }

        $messages = $this->ReadMessages();
        $found = false;
        foreach ($messages as &$message) {
            if ((string)($message['id'] ?? '') === $messageId) {
                if (!(bool)($message['acknowledged'] ?? false)) {
                    $message['acknowledged'] = true;
                    $message['acknowledgedAt'] = time();
                }
                $found = true;
                break;
            }
        }
        unset($message);

        if (!$found) {
            throw new InvalidArgumentException('Die Nachricht wurde nicht gefunden oder bereits gelöscht.');
        }

        // Eine bereits abgelaufene, nun quittierte Nachricht wird sofort bereinigt.
        $this->WriteMessages($messages);
        $this->Cleanup();
    }

    private function ReadMessages(): array
    {
        $raw = $this->GetValue(self::IDENT_MESSAGES);
        $messages = json_decode(is_string($raw) ? $raw : '[]', true);
        if (!is_array($messages)) {
            return [];
        }

        return array_values(array_filter($messages, static fn($item): bool => is_array($item)));
    }

    private function WriteMessages(array $messages): void
    {
        $json = json_encode(array_values($messages), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            throw new RuntimeException('Nachrichten konnten nicht gespeichert werden.');
        }

        $this->SetValue(self::IDENT_MESSAGES, $json);
    }
}
