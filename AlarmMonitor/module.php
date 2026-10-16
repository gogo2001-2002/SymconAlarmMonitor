<?php

declare(strict_types=1);

class AlarmMonitor extends IPSModule
{
    private const TIMER_INTERVAL_MS = 1000;

    /**
     * Baut die Echo-Auswahllisten dynamisch aus vorhandenen EchoRemote-Instanzen auf.
     * Die Auswahlfelder werden sowohl im Detaildialog als auch in der Zeilenkonfiguration
     * mit den aktuellen Instanz-IDs und Namen befüllt.
     */
    public function GetConfigurationForm()
    {
        $this->MigrateLegacyEchoIDs();
        $this->MigrateLegacyPushVisualizationIDs();
        $formPath = __DIR__ . '/form.json';
        $formJson = @file_get_contents($formPath);
        $form = is_string($formJson) ? json_decode($formJson, true) : null;
        if (!is_array($form)) {
            return is_string($formJson) ? $formJson : '{"elements":[],"actions":[]}';
        }

        $options = $this->GetEchoRemoteOptions();
        $visualizationOptions = $this->GetVisualizationOptions();
        foreach ($form['elements'] as &$element) {
            if (!is_array($element) || ($element['type'] ?? '') !== 'List' || ($element['name'] ?? '') !== 'Rules') {
                continue;
            }

            // Detaildialog: Select-Felder dynamisch befüllen, auch wenn sie in RowLayout-Elementen liegen.
            if (isset($element['form']) && is_array($element['form'])) {
                $this->ApplyDynamicOptionsToFormElements($element['form'], $options, $visualizationOptions);
            }

            // Falls ein Echo-Feld später auch als Tabellenspalte definiert wird,
            // müssen dessen Inline-Optionen dieselbe Liste erhalten.
            if (isset($element['columns']) && is_array($element['columns'])) {
                foreach ($element['columns'] as &$column) {
                    if (!is_array($column)) {
                        continue;
                    }
                    $columnName = (string)($column['name'] ?? '');
                    if (!isset($column['edit']) || !is_array($column['edit'])) {
                        continue;
                    }
                    if (preg_match('/^EchoInstanceID[1-4]$/', $columnName)) {
                        $column['edit']['options'] = $options;
                    } elseif (preg_match('/^PushVisualizationID[1-4]$/', $columnName)) {
                        $column['edit']['options'] = $visualizationOptions;
                    }
                }
                unset($column);
            }
        }
        unset($element);

        return json_encode($form, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: $formJson;
    }

    /** Befüllt dynamische Echo- und Visualisierungsauswahlen rekursiv, auch in RowLayout-Elementen. */
    private function ApplyDynamicOptionsToFormElements(array &$elements, array $echoOptions, array $visualizationOptions): void
    {
        foreach ($elements as &$element) {
            if (!is_array($element)) {
                continue;
            }
            $fieldName = (string)($element['name'] ?? '');
            if (preg_match('/^EchoInstanceID[1-4]$/', $fieldName)) {
                $element['options'] = array_values($echoOptions);
            } elseif (preg_match('/^PushVisualizationID[1-4]$/', $fieldName)) {
                $element['options'] = array_values($visualizationOptions);
            }
            if (isset($element['items']) && is_array($element['items'])) {
                $this->ApplyDynamicOptionsToFormElements($element['items'], $echoOptions, $visualizationOptions);
            }
        }
        unset($element);
    }

    /** Erzeugt die Auswahloptionen für vorhandene EchoRemote-Instanzen. */
    private function GetEchoRemoteOptions(): array
    {
        $options = [['caption' => 'Keine Auswahl', 'value' => 0]];
        $instances = [];

        foreach (IPS_GetInstanceList() as $instanceID) {
            $id = (int)$instanceID;
            try {
                $instance = IPS_GetInstance($id);
                $moduleInfo = $instance['ModuleInfo'] ?? [];
                $moduleName = is_array($moduleInfo) ? (string)($moduleInfo['ModuleName'] ?? '') : '';
                $moduleID = is_array($moduleInfo) ? (string)($moduleInfo['ModuleID'] ?? '') : '';
                $instanceName = trim((string)IPS_GetName($id));

                // Je nach Version/Modul werden unterschiedliche Metadaten befüllt.
                // Daher zusätzlich die Modul-Metadaten selbst abfragen, sofern verfügbar.
                $moduleMetadata = [];
                if ($moduleID !== '' && function_exists('IPS_GetModule')) {
                    try {
                        $metadata = IPS_GetModule($moduleID);
                        if (is_array($metadata)) {
                            foreach (['Name', 'ModuleName', 'Vendor', 'ModuleID'] as $key) {
                                if (isset($metadata[$key]) && is_scalar($metadata[$key])) {
                                    $moduleMetadata[] = (string)$metadata[$key];
                                }
                            }
                        }
                    } catch (Throwable $e) {
                        // Einige Symcon-Versionen stellen nicht alle Metadaten bereit.
                    }
                }

                $moduleText = strtolower($moduleName . ' ' . implode(' ', $moduleMetadata));
                $normalizedModule = preg_replace('/[^a-z0-9]/', '', $moduleText) ?? '';
                $normalizedInstanceName = preg_replace('/[^a-z0-9]/', '', strtolower($instanceName)) ?? '';

                // Primär anhand des Modultyps erkennen. Der Instanzname dient als
                // Fallback für Installationen, bei denen IPS_GetInstance keine
                // aussagekräftigen Modulnamen liefert.
                $isEchoRemote = strpos($normalizedModule, 'echoremote') !== false;
                if (!$isEchoRemote && $moduleName === '' && $moduleID === '') {
                    $isEchoRemote = strpos($normalizedInstanceName, 'echoremote') !== false;
                }
                if (!$isEchoRemote) {
                    continue;
                }

                $name = $instanceName !== '' ? $instanceName : 'Ohne Namen';
                $instances[] = ['caption' => $name . ' (ID ' . $id . ')', 'value' => $id];
            } catch (Throwable $e) {
                // Instanz kann während der Ermittlung gelöscht worden sein.
            }
        }

        usort($instances, static fn(array $a, array $b): int => strnatcasecmp($a['caption'], $b['caption']));
        return array_merge($options, $instances);
    }

    /** Erzeugt eine Auswahlliste ausschließlich für WebFront- und Kachel-Visualisierungen. */
    private function GetVisualizationOptions(): array
    {
        $options = [['caption' => 'Keine Auswahl', 'value' => 0]];
        $instances = [];
        foreach (IPS_GetInstanceList() as $instanceID) {
            $id = (int)$instanceID;
            try {
                $instance = IPS_GetInstance($id);
                $moduleInfo = $instance['ModuleInfo'] ?? [];
                $moduleName = is_array($moduleInfo) ? (string)($moduleInfo['ModuleName'] ?? '') : '';
                $moduleID = is_array($moduleInfo) ? strtoupper((string)($moduleInfo['ModuleID'] ?? '')) : '';
                $normalized = preg_replace('/[^a-z0-9]/', '', strtolower($moduleName)) ?? '';
                $isWebFront = $moduleID === '{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}' || strpos($normalized, 'webfront') !== false;
                $isTileVisualization = strpos($normalized, 'kachel') !== false
                    || strpos($normalized, 'tilevisualization') !== false
                    || strpos($normalized, 'visualization') !== false
                    || strpos($normalized, 'visualisierung') !== false;
                if (!$isWebFront && !$isTileVisualization) {
                    continue;
                }
                $name = trim((string)IPS_GetName($id));
                $instances[] = ['caption' => ($name !== '' ? $name : $moduleName) . ' (ID ' . $id . ')', 'value' => $id];
            } catch (Throwable $e) {
                // Instanz kann während der Ermittlung gelöscht worden sein.
            }
        }
        usort($instances, static fn(array $a, array $b): int => strnatcasecmp($a['caption'], $b['caption']));
        return array_merge($options, $instances);
    }

    /** Migriert das frühere globale Push-Ziel einmalig in jede Push-aktivierte Regel. */
    private function MigrateLegacyPushVisualizationIDs(): void
    {
        $legacyID = (int)$this->ReadPropertyInteger('PushVisualizationID');
        if ($legacyID <= 0) {
            return;
        }
        $rules = json_decode($this->ReadPropertyString('Rules'), true);
        if (!is_array($rules)) {
            return;
        }
        $hasPerRuleFields = false;
        foreach ($rules as $rule) {
            if (!is_array($rule)) continue;
            for ($i = 1; $i <= 4; $i++) {
                if (array_key_exists('PushVisualizationID' . $i, $rule)) {
                    $hasPerRuleFields = true;
                    break 2;
                }
            }
        }
        if (!$hasPerRuleFields) {
            foreach ($rules as &$rule) {
                if (!is_array($rule)) continue;
                $enabled = filter_var($rule['PushEnabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
                for ($i = 1; $i <= 4; $i++) {
                    $rule['PushVisualizationID' . $i] = ($enabled && $i === 1) ? $legacyID : 0;
                }
            }
            unset($rule);
            IPS_SetProperty($this->InstanceID, 'Rules', json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
        // Der alte Parameter bleibt intern nur für die Migration registriert, wird aber nicht mehr verwendet.
        IPS_SetProperty($this->InstanceID, 'PushVisualizationID', 0);
    }

    /** Liest die pro Regel ausgewählten Push-Ziele, entfernt Duplikate und ungültige IDs. */
    private function GetRulePushVisualizationIDs(array $rule): array
    {
        $ids = [];
        for ($i = 1; $i <= 4; $i++) {
            $id = (int)($rule['PushVisualizationID' . $i] ?? 0);
            if ($id > 0 && !in_array($id, $ids, true) && IPS_InstanceExists($id)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /**
     * Migriert bisherige kommagetrennte EchoInstanceIDs in bis zu vier auswählbare IDs.
     * Die Migration läuft nur, wenn alte Daten vorhanden sind und noch keine neuen Felder.
     */
    private function MigrateLegacyEchoIDs(): void
    {
        $rules = json_decode($this->ReadPropertyString('Rules'), true);
        if (!is_array($rules)) return;
        $changed = false;
        foreach ($rules as &$rule) {
            if (!is_array($rule) || !array_key_exists('EchoInstanceIDs', $rule)) continue;
            $hasNewFields = false;
            for ($i = 1; $i <= 4; $i++) {
                if ((int)($rule['EchoInstanceID' . $i] ?? 0) > 0) $hasNewFields = true;
            }
            if (!$hasNewFields) {
                $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string)$rule['EchoInstanceIDs'])), static fn(int $id): bool => $id > 0)));
                for ($i = 1; $i <= 4; $i++) $rule['EchoInstanceID' . $i] = $ids[$i - 1] ?? 0;
            }
            unset($rule['EchoInstanceIDs']);
            $changed = true;
        }
        unset($rule);
        if ($changed) {
            IPS_SetProperty($this->InstanceID, 'Rules', json_encode($rules, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        }
    }

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Rules', '[]');
        $this->RegisterPropertyInteger('MailInstanceID', 0);
        $this->RegisterPropertyInteger('PushVisualizationID', 0);
        $this->RegisterPropertyInteger('CallModuleInstanceID', 0);
        $this->RegisterTimer('EvaluateRules', 0, "ALM_EvaluateRules(\$_IPS['TARGET']);");
        $this->SetBuffer('RuleState', '{}');
        $this->SetBuffer('RegisteredVariables', '[]');
        $this->SetBuffer('RulesRuntime', '[]');
    }

    /**
     * Wird direkt beim Ändern des Operators im Dialog einer Listenzeile aufgerufen.
     * Bei CHANGED und UPDATED wird der Vergleichswert im Zeilendialog auf 0 gesetzt.
     */
    public function RulesChanged($operator): void
    {
        if (in_array((string)$operator, ['CHANGED', 'UPDATED'], true)) {
            $this->UpdateFormField('CompareValue', 'value', '0');
        }
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->MigrateLegacyPushVisualizationIDs();

        $oldIDs = json_decode($this->GetBuffer('RegisteredVariables'), true);
        if (is_array($oldIDs)) {
            foreach (array_unique(array_map('intval', $oldIDs)) as $id) {
                if ($id > 0) {
                    $this->UnregisterMessage($id, VM_UPDATE);
                    $this->UnregisterMessage($id, VM_DELETE);
                }
            }
        }

        $rules = $this->GetRules();
        // Bei zeitbasierten Bedingungen wird kein Vergleichswert verwendet.
        // Den gespeicherten Wert deshalb beim Anwenden der Konfiguration immer auf 0 normalisieren.
        $normalizedRules = $rules;
        $rulesChanged = false;
        foreach ($normalizedRules as &$configuredRule) {
            if (!is_array($configuredRule)) {
                continue;
            }
            // Rückwärtskompatibilität für das neue Aktiv-Flag:
            // Alte Regeln ohne Marker werden einmalig aktiviert. Danach gilt ein
            // fehlendes Enabled-Feld bei vorhandenem Marker als deaktiviert, da
            // IP-Symcon abgewählte Checkboxen aus der Regelliste entfernen kann.
            if (!array_key_exists('EnabledConfigured', $configuredRule)) {
                $configuredRule['Enabled'] = true;
                $configuredRule['EnabledConfigured'] = true;
                $rulesChanged = true;
            } else {
                if (!array_key_exists('Enabled', $configuredRule)) {
                    $configuredRule['Enabled'] = false;
                    $rulesChanged = true;
                }
                if ($configuredRule['EnabledConfigured'] !== true) {
                    $configuredRule['EnabledConfigured'] = true;
                    $rulesChanged = true;
                }
            }
            // Vergleichsquelle wird allein durch die Auswahl bestimmt:
            // Vergleichsvariable > 0 => Variablenwert, sonst fester Vergleichswert.
            if (!array_key_exists('CompareVariableID', $configuredRule)) {
                $configuredRule['CompareVariableID'] = 0;
                $rulesChanged = true;
            }
            $derivedUseCompareVariable = (int)$configuredRule['CompareVariableID'] > 0;
            if (($configuredRule['UseCompareVariable'] ?? null) !== $derivedUseCompareVariable) {
                $configuredRule['UseCompareVariable'] = $derivedUseCompareVariable;
                $rulesChanged = true;
            }

            // Neue Regeln verwenden sichere Standardwerte: Erstmeldung und Eskalation
            // sind deaktiviert, bis der Anwender sie ausdrücklich einschaltet.
            // Fehlende Checkbox-Schlüssel werden als deaktiviert interpretiert, da
            // Symcon nicht ausgewählte Checkboxen aus der Regelliste auslassen kann.
            $phaseFlags = ['PushOnFirst', 'PushOnEscalation', 'EmailOnFirst', 'EmailOnEscalation', 'CallOnFirst', 'CallOnEscalation', 'EchoOnFirst', 'EchoOnEscalation'];
            foreach ($phaseFlags as $phaseFlag) {
                if (!array_key_exists($phaseFlag, $configuredRule)) {
                    $configuredRule[$phaseFlag] = false;
                    $rulesChanged = true;
                }
            }
            if (in_array((string)($configuredRule['Operator'] ?? ''), ['CHANGED', 'UPDATED'], true)
                && (string)($configuredRule['CompareValue'] ?? '') !== '0') {
                $configuredRule['CompareValue'] = '0';
                $rulesChanged = true;
            }
        }
        unset($configuredRule);
        if ($rulesChanged) {
            IPS_SetProperty($this->InstanceID, 'Rules', json_encode($normalizedRules, JSON_UNESCAPED_UNICODE));
            $rules = $normalizedRules;
        }
        $validRules = [];
        $registered = [];
        $activeAlarmIdents = [];
        $activeEscalationIdents = [];
        $activeStateKeys = [];
        $states = json_decode($this->GetBuffer('RuleState'), true);
        if (!is_array($states)) $states = [];

        foreach ($rules as $index => $rule) {
            if (!is_array($rule)) continue;
            $variableID = (int)($rule['VariableID'] ?? 0);
            if ($variableID <= 0 || !IPS_VariableExists($variableID)) continue;

            $operator = (string)($rule['Operator'] ?? '==');
            if (!in_array($operator, ['==', '!=', '>=', '>', '<', '<=', 'CHANGED', 'UPDATED'], true)) $operator = '==';
            $compareValue = (string)($rule['CompareValue'] ?? '1');
            $compareVariableID = (int)($rule['CompareVariableID'] ?? 0);
            $useCompareVariable = $compareVariableID > 0;
            $delay = max(0, min(86400, (int)($rule['Delay'] ?? 0)));
            $escalationMinutes = max(0, min(10080, (int)($rule['EscalationMinutes'] ?? 0)));
            $enabled = filter_var($rule['Enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $stateKey = $this->StateKey($variableID, (int)$index);
            $ident = $this->AlarmIdent($variableID, (int)$index);
            $escalationIdent = $this->EscalationIdent($variableID, (int)$index);
            $signature = json_encode([$variableID, $operator, $compareValue, $useCompareVariable, $compareVariableID, $delay, $escalationMinutes, $enabled], JSON_UNESCAPED_UNICODE);

            $validRules[] = [
                'Index' => (int)$index,
                'Enabled' => $enabled,
                'VariableID' => $variableID,
                'Operator' => $operator,
                'CompareValue' => $compareValue,
                'UseCompareVariable' => $useCompareVariable,
                'CompareVariableID' => $compareVariableID,
                'Delay' => $delay,
                'EscalationMinutes' => $escalationMinutes,
                'PushOnFirst' => filter_var($rule['PushOnFirst'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'PushOnEscalation' => filter_var($rule['PushOnEscalation'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'PushVisualizationIDs' => $this->GetRulePushVisualizationIDs($rule),
                'PushText' => trim((string)($rule['PushText'] ?? '')),
                'EmailOnFirst' => filter_var($rule['EmailOnFirst'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'EmailOnEscalation' => filter_var($rule['EmailOnEscalation'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'EmailRecipients' => trim((string)($rule['EmailRecipients'] ?? '')),
                'CallOnFirst' => filter_var($rule['CallOnFirst'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'CallOnEscalation' => filter_var($rule['CallOnEscalation'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'PhoneNumbers' => trim((string)($rule['PhoneNumbers'] ?? '')),
                'EchoOnFirst' => filter_var($rule['EchoOnFirst'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'EchoOnEscalation' => filter_var($rule['EchoOnEscalation'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'EchoInstanceIDs' => $this->GetRuleEchoInstanceIDs($rule),
                'PushSound' => $this->NormalizePushSound((string)($rule['PushSound'] ?? 'alarm')),
                'StateKey' => $stateKey,
                'AlarmIdent' => $ident,
                'EscalationIdent' => $escalationIdent,
                'Signature' => $signature
            ];
            $activeAlarmIdents[] = $ident;
            $activeEscalationIdents[] = $escalationIdent;
            $activeStateKeys[] = $stateKey;
            $this->EnsureAlarmVariable($variableID, (int)$index, $operator, $compareValue, $delay, trim((string)($rule['PushText'] ?? '')), $useCompareVariable, $compareVariableID);
            $this->EnsureEscalationVariable($variableID, (int)$index, $operator, $compareValue, $delay, trim((string)($rule['PushText'] ?? '')), $useCompareVariable, $compareVariableID);

            $variablesToRegister = [$variableID];
            if ($useCompareVariable && $compareVariableID > 0 && IPS_VariableExists($compareVariableID)) {
                $variablesToRegister[] = $compareVariableID;
            }
            foreach (array_unique($variablesToRegister) as $registerID) {
                if (!in_array($registerID, $registered, true)) {
                    $this->RegisterMessage($registerID, VM_UPDATE);
                    $this->RegisterMessage($registerID, VM_DELETE);
                    $registered[] = $registerID;
                }
            }

            if (!isset($states[$stateKey]) || !is_array($states[$stateKey]) || ($states[$stateKey]['signature'] ?? '') !== $signature) {
                $states[$stateKey] = ['since' => 0, 'alarm' => false, 'lastUpdate' => time(), 'signature' => $signature, 'alarmSince' => 0, 'nextEscalationAt' => 0];
            } else {
                $states[$stateKey]['since'] = (int)($states[$stateKey]['since'] ?? 0);
                $states[$stateKey]['alarm'] = (bool)($states[$stateKey]['alarm'] ?? false);
                if ((int)($states[$stateKey]['lastUpdate'] ?? 0) <= 0) $states[$stateKey]['lastUpdate'] = time();
                $states[$stateKey]['alarmSince'] = (int)($states[$stateKey]['alarmSince'] ?? 0);
                $states[$stateKey]['nextEscalationAt'] = (int)($states[$stateKey]['nextEscalationAt'] ?? 0);
            }
        }

        foreach (array_keys($states) as $stateKey) {
            if (!in_array($stateKey, $activeStateKeys, true)) unset($states[$stateKey]);
        }

        // Alte Alarmvariablen (z. B. aus der früheren Version mit nur einer Variable je Quelle) entfernen.
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $childID) {
            $object = IPS_GetObject($childID);
            $ident = (string)($object['ObjectIdent'] ?? '');
            if ((str_starts_with($ident, 'Alarm_') && !in_array($ident, $activeAlarmIdents, true)) ||
                (str_starts_with($ident, 'Escalation_') && !in_array($ident, $activeEscalationIdents, true))) {
                IPS_DeleteVariable($childID);
            }
        }

        $this->SetBuffer('RulesRuntime', json_encode($validRules, JSON_UNESCAPED_UNICODE));
        $this->SetBuffer('RuleState', json_encode($states));
        $this->SetBuffer('RegisteredVariables', json_encode($registered));
        $this->SetTimerInterval('EvaluateRules', count($validRules) > 0 ? self::TIMER_INTERVAL_MS : 0);
        $this->EvaluateRules();
    }

    public function MessageSink($TimeStamp, $SenderID, $Message, $Data)
    {
        $senderID = (int)$SenderID;
        if ($Message === VM_DELETE) {
            $configuredRules = [];
            foreach ($this->GetRules() as $configuredRule) {
                if (!is_array($configuredRule)) continue;
                if ((int)($configuredRule['VariableID'] ?? 0) === $senderID) {
                    continue; // Überwachte Variable gelöscht: Regel entfernen.
                }
                if ((int)($configuredRule['CompareVariableID'] ?? 0) === $senderID) {
                    // Vergleichsvariable gelöscht: Auswahl leeren, Variablenmodus aber
                    // eingeschaltet lassen, damit nicht versehentlich der Festwert gilt.
                    $configuredRule['CompareVariableID'] = 0;
                    $configuredRule['UseCompareVariable'] = false;
                }
                $configuredRules[] = $configuredRule;
            }
            IPS_SetProperty($this->InstanceID, 'Rules', json_encode($configuredRules, JSON_UNESCAPED_UNICODE));
            $states = json_decode($this->GetBuffer('RuleState'), true);
            if (is_array($states)) {
                foreach ($states as $key => $_state) if (str_starts_with((string)$key, $senderID . ':')) unset($states[$key]);
                $this->SetBuffer('RuleState', json_encode($states));
            }
            $this->ApplyChanges();
            return;
        }

        if ($Message === VM_UPDATE) {
            $rules = json_decode($this->GetBuffer('RulesRuntime'), true);
            $states = json_decode($this->GetBuffer('RuleState'), true);
            if (!is_array($states)) $states = [];
            if (is_array($rules)) {
                foreach ($rules as $rule) {
                    if ((int)$rule['VariableID'] !== $senderID || $rule['Operator'] !== 'CHANGED') continue;
                    $key = (string)$rule['StateKey'];
                    if (!isset($states[$key]) || !is_array($states[$key])) $states[$key] = [];
                    $states[$key]['lastUpdate'] = time();
                    $states[$key]['since'] = 0;
                    $states[$key]['alarm'] = false;
                    $alarmID = @IPS_GetObjectIDByIdent((string)$rule['AlarmIdent'], $this->InstanceID);
                    if ($alarmID !== false && GetValueBoolean($alarmID)) SetValueBoolean($alarmID, false);
                }
            }
            $this->SetBuffer('RuleState', json_encode($states));
            $this->EvaluateRules();
        }
    }

    public function EvaluateRules()
    {
        $rules = json_decode($this->GetBuffer('RulesRuntime'), true);
        if (!is_array($rules) || count($rules) === 0) {
            $this->SetTimerInterval('EvaluateRules', 0);
            return;
        }
        $states = json_decode($this->GetBuffer('RuleState'), true);
        if (!is_array($states)) $states = [];
        $now = time();

        foreach ($rules as $rule) {
            $variableID = (int)$rule['VariableID'];
            if (!IPS_VariableExists($variableID)) continue;
            $key = (string)$rule['StateKey'];
            if (!isset($states[$key]) || !is_array($states[$key])) {
                $states[$key] = ['since' => 0, 'alarm' => false, 'lastUpdate' => $now, 'signature' => $rule['Signature'], 'alarmSince' => 0, 'nextEscalationAt' => 0];
            }
            $alarmID = @IPS_GetObjectIDByIdent((string)$rule['AlarmIdent'], $this->InstanceID);
            if ($alarmID === false) $alarmID = $this->EnsureAlarmVariable($variableID, (int)$rule['Index'], (string)$rule['Operator'], (string)$rule['CompareValue'], (int)$rule['Delay'], (string)($rule['PushText'] ?? ''), ((int)($rule['CompareVariableID'] ?? 0) > 0), (int)($rule['CompareVariableID'] ?? 0));

            // Deaktivierte Regel: keine Erstmeldung und keine Eskalation ausführen.
            // Eine eventuell aktive Alarmvariable wird ohne Benachrichtigung zurückgesetzt.
            if (!filter_var($rule['Enabled'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
                if (GetValueBoolean($alarmID)) {
                    SetValueBoolean($alarmID, false);
                }
                $this->SetEscalationCount($rule, 0);
                $states[$key]['since'] = 0;
                $states[$key]['alarm'] = false;
                $states[$key]['alarmSince'] = 0;
                $states[$key]['nextEscalationAt'] = 0;
                continue;
            }

            if ((string)$rule['Operator'] === 'CHANGED') {
                // Ereignisbasierte Überwachung: Frist beginnt bei VM_UPDATE neu.
                $lastUpdate = (int)($states[$key]['lastUpdate'] ?? $now);
                $shouldAlarm = ($now - $lastUpdate) >= (int)$rule['Delay'];
                $states[$key]['alarm'] = $shouldAlarm;
                $this->SetAlarmState($alarmID, $shouldAlarm, $rule);
                $this->ProcessEscalation($alarmID, $shouldAlarm, $rule, $states[$key], $now);
                continue;
            }

            if ((string)$rule['Operator'] === 'UPDATED') {
                // Zeitstempelbasierte Überwachung: berücksichtigt VariableUpdated aus IPS_GetVariable,
                // auch nach einem Neustart oder wenn der Wert selbst unverändert geblieben ist.
                $variableInfo = IPS_GetVariable($variableID);
                $lastUpdatedTimestamp = (int)($variableInfo['VariableUpdated'] ?? 0);
                $shouldAlarm = $lastUpdatedTimestamp <= 0 || ($now - $lastUpdatedTimestamp) >= (int)$rule['Delay'];
                $states[$key]['alarm'] = $shouldAlarm;
                $this->SetAlarmState($alarmID, $shouldAlarm, $rule);
                $this->ProcessEscalation($alarmID, $shouldAlarm, $rule, $states[$key], $now);
                continue;
            }

            $compareVariableID = (int)($rule['CompareVariableID'] ?? 0);
            $useCompareVariable = $compareVariableID > 0;
            if ($useCompareVariable && ($compareVariableID <= 0 || !IPS_VariableExists($compareVariableID))) {
                // Im Variablenmodus niemals auf den Festwert zurückfallen.
                $this->LogMissingCompareVariableOnce($key, $compareVariableID);
                $states[$key]['since'] = 0;
                $states[$key]['alarm'] = false;
                $this->SetAlarmState($alarmID, false, $rule);
                $this->ProcessEscalation($alarmID, false, $rule, $states[$key], $now);
                continue;
            }
            $this->ClearMissingCompareVariableWarning($key);
            $condition = $this->ConditionMatches($variableID, (string)$rule['Operator'], (string)$rule['CompareValue'], $useCompareVariable, $compareVariableID);
            if (!$condition) {
                $states[$key]['since'] = 0;
                $states[$key]['alarm'] = false;
                $this->SetAlarmState($alarmID, false, $rule);
                $this->ProcessEscalation($alarmID, false, $rule, $states[$key], $now);
                continue;
            }
            if ((int)($states[$key]['since'] ?? 0) === 0) $states[$key]['since'] = $now;
            $shouldAlarm = ($now - (int)$states[$key]['since']) >= (int)$rule['Delay'];
            $states[$key]['alarm'] = $shouldAlarm;
            $this->SetAlarmState($alarmID, $shouldAlarm, $rule);
            $this->ProcessEscalation($alarmID, $shouldAlarm, $rule, $states[$key], $now);
        }
        $this->SetBuffer('RuleState', json_encode($states));
    }

    /**
     * Ändert den Alarmzustand und sendet optional genau beim Übergang FALSE -> TRUE eine Push-Nachricht.
     */
    private function SetAlarmState(int $alarmID, bool $shouldAlarm, array $rule): void
    {
        $wasAlarm = GetValueBoolean($alarmID);
        if ($wasAlarm === $shouldAlarm) {
            return;
        }

        SetValueBoolean($alarmID, $shouldAlarm);
        if (!$wasAlarm && $shouldAlarm) {
            $this->SendPushForRule($rule, time(), false);
            $this->SendEmailForRule($rule, time(), false);
            $this->StartCallsForRule($rule, false);
            $this->SendEchoAnnouncementForRule($rule, false);
        }
    }

    /**
     * Wiederholt die konfigurierten Benachrichtigungen im eingestellten Intervall, solange der Alarm aktiv bleibt.
     * 0 Minuten deaktiviert die Eskalation. Der ursprüngliche Auslösezeitpunkt bleibt für alle Wiederholungen erhalten.
     */
    private function ProcessEscalation(int $alarmID, bool $shouldAlarm, array $rule, array &$state, int $now): void
    {
        $isActive = $shouldAlarm && GetValueBoolean($alarmID);
        $intervalMinutes = max(0, min(10080, (int)($rule['EscalationMinutes'] ?? 0)));

        if (!$isActive) {
            $this->SetEscalationCount($rule, 0);
            $state['alarmSince'] = 0;
            $state['nextEscalationAt'] = 0;
            return;
        }

        if ((int)($state['alarmSince'] ?? 0) <= 0) {
            $state['alarmSince'] = $now;
        }

        if ($intervalMinutes === 0) {
            $state['nextEscalationAt'] = 0;
            return;
        }

        $intervalSeconds = $intervalMinutes * 60;
        $nextEscalationAt = (int)($state['nextEscalationAt'] ?? 0);
        if ($nextEscalationAt <= 0) {
            // Bei neu aktivierter Eskalation bzw. nach einem Neustart beginnt das Intervall ab jetzt.
            $state['nextEscalationAt'] = $now + $intervalSeconds;
            return;
        }

        if ($now >= $nextEscalationAt) {
            $this->SendDebug('Escalation', 'Alarm bleibt aktiv; sende Wiederholungsbenachrichtigung für Regel ' . $rule['StateKey'] . '.', 0);
            $this->IncrementEscalationCount($rule);
            $alarmSince = (int)$state['alarmSince'];
            $this->SendPushForRule($rule, $alarmSince, true);
            $this->SendEmailForRule($rule, $alarmSince, true);
            $this->StartCallsForRule($rule, true);
            $this->SendEchoAnnouncementForRule($rule, true);
            // Kein Nachholen mehrerer verpasster Intervalle auf einmal; der nächste Versand erfolgt in X Minuten.
            $state['nextEscalationAt'] = $now + $intervalSeconds;
        }
    }

    /**
     * Formatiert den Alarmtext abhängig von der Phase. Bei Eskalationen wird der Präfix
     * genau einmal ergänzt; bei Erstmeldungen bleibt der eigentliche Alarmtext unverändert.
     */
    private function FormatNotificationAlarmText(string $alarmText, bool $escalation, array $rule): string
    {
        $alarmText = trim($alarmText);
        // Einen bereits vorhandenen Eskalationspräfix entfernen, damit er nie doppelt erscheint.
        $alarmText = preg_replace('/^(?:\\d+\\.\\s*)?Eskalation:\\s*/iu', '', $alarmText) ?? $alarmText;
        if (!$escalation || $alarmText === '') {
            return $alarmText;
        }

        $count = $this->GetEscalationCount($rule);
        return $count . '. Eskalation: ' . $alarmText;
    }

    /** Liefert die aktuelle Nummer der Eskalation für die Benachrichtigung. */
    private function GetEscalationCount(array $rule): int
    {
        $ident = (string)($rule['EscalationIdent'] ?? '');
        if ($ident === '') {
            return 0;
        }
        $escalationID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($escalationID === false || !IPS_VariableExists((int)$escalationID)) {
            return 0;
        }
        return max(0, GetValueInteger((int)$escalationID));
    }

    /**
     * Startet optional ausgehende Anrufe über die separat installierte TelefonansageDirekt-Instanz.
     * Telefonnummern werden kommasepariert angegeben; die Warteschlange verwaltet das Anrufmodul selbst.
     */
    private function StartCallsForRule(array $rule, bool $escalation = false): void
    {
        $phaseFlag = $escalation ? 'CallOnEscalation' : 'CallOnFirst';
        if (!($rule[$phaseFlag] ?? false)) {
            $this->SendDebug('CallNotification', 'Anruf übersprungen: Für diese Regel ist der Anruf für ' . ($escalation ? 'Eskalationen' : 'die Erstmeldung') . ' deaktiviert.', 0);
            return;
        }

        $phoneText = trim((string)($rule['PhoneNumbers'] ?? ''));
        $phoneNumbers = array_values(array_unique(array_filter(array_map('trim', explode(',', $phoneText)), static fn(string $phone): bool => $phone !== '')));
        if (count($phoneNumbers) === 0) {
            $this->SendDebug('CallNotification', 'Anruf übersprungen: Es wurden keine Telefonnummern eingetragen. Es wird kein Anruf gestartet.', 0);
            return;
        }

        $callInstanceID = (int)$this->ReadPropertyInteger('CallModuleInstanceID');
        if ($callInstanceID <= 0 || !IPS_InstanceExists($callInstanceID)) {
            $this->SendDebug('CallNotification', 'Anruf ist aktiviert, aber keine gültige TelefonansageDirekt-Instanz ist global ausgewählt.', 0);
            return;
        }

        if (!function_exists('TAD_StartCall')) {
            $this->SendDebug('CallNotification', 'TAD_StartCall ist nicht verfügbar. Prüfe, ob das Modul TelefonansageDirekt installiert und geladen ist.', 0);
            return;
        }

        $alarmText = trim((string)($rule['PushText'] ?? ''));
        if ($alarmText === '') {
            $alarmText = 'Alarm';
        }
        $alarmText = $this->FormatNotificationAlarmText($alarmText, $escalation, $rule);
        foreach ($phoneNumbers as $phoneNumber) {
            try {
                // Öffentliche API des eigenständigen TelefonansageDirekt-Moduls:
                // TAD_StartCall(Instanz-ID, Telefonnummer, Ansagetext)
                TAD_StartCall($callInstanceID, $phoneNumber, $alarmText);
                $this->SendDebug('CallNotification', 'Anruf an ' . $phoneNumber . ' wurde an TelefonansageDirekt übergeben.', 0);
            } catch (Throwable $e) {
                $this->SendDebug('CallNotification', 'Fehler beim Übergeben des Anrufs an ' . $phoneNumber . ': ' . $e->getMessage(), 0);
            }
        }
    }


    /** Erstellt aus den vier Auswahlfeldern die Liste der ausgewählten Echo-IDs. */
    private function GetRuleEchoInstanceIDs(array $rule): string
    {
        $ids = [];
        for ($i = 1; $i <= 4; $i++) {
            $id = (int)($rule['EchoInstanceID' . $i] ?? 0);
            if ($id > 0) $ids[] = $id;
        }
        // Rückwärtskompatibilität, falls eine alte Regel vor der Migration verarbeitet wird.
        if (count($ids) === 0 && isset($rule['EchoInstanceIDs'])) {
            $ids = array_values(array_filter(array_map('intval', explode(',', (string)$rule['EchoInstanceIDs'])), static fn(int $id): bool => $id > 0));
        }
        return implode(',', array_values(array_unique($ids)));
    }

    /**
     * Gibt den Alarmtext optional über Echo Remote 2 auf den pro Regel ausgewählten Echo-Geräten aus.
     */
    private function SendEchoAnnouncementForRule(array $rule, bool $escalation = false): void
    {
        $phaseFlag = $escalation ? 'EchoOnEscalation' : 'EchoOnFirst';
        if (!($rule[$phaseFlag] ?? false)) {
            $this->SendDebug('EchoAnnouncement', 'Echo-Ausgabe übersprungen: Für diese Regel ist Echo für ' . ($escalation ? 'Eskalationen' : 'die Erstmeldung') . ' deaktiviert.', 0);
            return;
        }

        $instanceText = trim((string)($rule['EchoInstanceIDs'] ?? ''));
        if ($instanceText === '') {
            $this->SendDebug('EchoAnnouncement', 'Echo-Ausgabe ist aktiviert, aber es wurden keine Echo-Instanz-IDs eingetragen.', 0);
            return;
        }

        if (!function_exists('ECHOREMOTE_Announcement')) {
            $this->SendDebug('EchoAnnouncement', 'ECHOREMOTE_Announcement ist nicht verfügbar. Prüfe, ob Echo Remote 2 installiert und geladen ist.', 0);
            return;
        }

        $alarmText = trim((string)($rule['PushText'] ?? ''));
        if ($alarmText === '') {
            $alarmText = 'Alarm';
        }
        $alarmText = $this->FormatNotificationAlarmText($alarmText, $escalation, $rule);

        $instanceIDs = array_values(array_unique(array_filter(
            array_map(static fn(string $id): int => (int)trim($id), explode(',', $instanceText)),
            static fn(int $id): bool => $id > 0
        )));
        if (count($instanceIDs) === 0) {
            $this->SendDebug('EchoAnnouncement', 'Echo-Ausgabe übersprungen: keine gültigen positiven Instanz-IDs gefunden.', 0);
            return;
        }

        foreach ($instanceIDs as $instanceID) {
            if (!IPS_InstanceExists($instanceID)) {
                $this->SendDebug('EchoAnnouncement', 'Echo-Instanz ' . $instanceID . ' existiert nicht; übersprungen.', 0);
                continue;
            }

            try {
                // Öffentliche API von Echo Remote 2: ECHOREMOTE_Announcement(Echo-Instanz-ID, Text)
                ECHOREMOTE_Announcement($instanceID, $alarmText);
                $this->SendDebug('EchoAnnouncement', 'Alarmtext wurde an Echo-Instanz ' . $instanceID . ' übergeben.', 0);
            } catch (Throwable $e) {
                $this->SendDebug('EchoAnnouncement', 'Fehler bei Echo-Instanz ' . $instanceID . ': ' . $e->getMessage(), 0);
            }
        }
    }

    /**
     * Sendet beim erstmaligen Auslösen der Bedingung optional eine E-Mail.
     * Die SMTP-Instanz ist global konfiguriert; Empfänger und Nachrichtentext sind regelbezogen.
     */
    private function SendEmailForRule(array $rule, int $alarmSince = 0, bool $escalation = false): void
    {
        // Die Phasenflags allein bestimmen, ob eine E-Mail bei Erstmeldung oder Eskalation gesendet wird.
        $phaseFlag = $escalation ? 'EmailOnEscalation' : 'EmailOnFirst';
        if (!($rule[$phaseFlag] ?? false)) {
            $this->SendDebug('EmailNotification', 'E-Mail übersprungen: Versand für ' . ($escalation ? 'Eskalationen' : 'die Erstmeldung') . ' ist für diese Regel deaktiviert.', 0);
            return;
        }

        $recipientText = trim((string)($rule['EmailRecipients'] ?? ''));
        $recipients = array_values(array_unique(array_filter(array_map('trim', explode(',', $recipientText)), static fn(string $address): bool => $address !== '')));
        if (count($recipients) === 0) {
            $this->SendDebug('EmailNotification', 'E-Mail übersprungen: Es wurden keine Empfänger eingetragen. Es wird keine E-Mail versendet.', 0);
            return;
        }

        $smtpInstanceID = (int)$this->ReadPropertyInteger('MailInstanceID');
        if ($smtpInstanceID <= 0 || !IPS_InstanceExists($smtpInstanceID)) {
            $this->SendDebug('EmailNotification', 'E-Mail-Empfänger eingetragen, aber keine gültige SMTP-Instanz ausgewählt.', 0);
            return;
        }

        if (!function_exists('SMTP_SendMailEx')) {
            $this->SendDebug('EmailNotification', 'SMTP_SendMailEx ist in dieser IP-Symcon-Installation nicht verfügbar.', 0);
            return;
        }

        $pushText = trim((string)($rule['PushText'] ?? ''));
        $notificationText = $this->FormatNotificationAlarmText($pushText, $escalation, $rule);

        $variableID = (int)($rule['VariableID'] ?? 0);
        if ($variableID <= 0 || !IPS_VariableExists($variableID)) {
            $this->SendDebug('EmailNotification', 'E-Mail übersprungen: Die überwachte Variable existiert nicht mehr.', 0);
            return;
        }

        $variableName = IPS_GetName($variableID);
        $operator = (string)($rule['Operator'] ?? '==');
        $compareValue = (string)($rule['CompareValue'] ?? '');
        $delay = (int)($rule['Delay'] ?? 0);
        $condition = $this->ConditionName($operator, $compareValue, ((int)($rule['CompareVariableID'] ?? 0) > 0), (int)($rule['CompareVariableID'] ?? 0));
        $triggerTime = date('d.m.Y H:i:s', $alarmSince > 0 ? $alarmSince : time()); // ursprünglicher Auslösezeitpunkt, 24-Stunden-Format
        $actualValue = $this->FormatVariableValue($variableID);

        $subjectText = preg_replace('/[\r\n]+/', ' ', $notificationText) ?? $notificationText;
        $subject = 'Alarmmanager : ' . trim($subjectText);
        $bodyLines = [
            'ALARM AUSGELÖST',
            '================',
            '',
            'Meldung:',
            ($notificationText !== '' ? $notificationText : '(kein Pushtext hinterlegt)'),
            '',
            'Details zur Bedingung',
            '---------------------',
            'Überwachte Variable: ' . $variableName . ' (ID ' . $variableID . ')',
            'Bedingung: ' . $condition,
            'Verzögerung: ' . $delay . ' Sekunden',
            '',
            'Zeitpunkt des Alarms: ' . $triggerTime,
            'Aktueller Variablenwert: ' . $actualValue
        ];
        $body = implode("\r\n", $bodyLines);

        $successCount = 0;
        foreach ($recipients as $recipient) {
            if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
                $this->SendDebug('EmailNotification', 'Ungültige E-Mail-Adresse übersprungen: ' . $recipient, 0);
                continue;
            }

            try {
                $result = SMTP_SendMailEx($smtpInstanceID, $recipient, $subject, $body);
                if ($result) {
                    $successCount++;
                    $this->SendDebug('EmailNotification', 'E-Mail an ' . $recipient . ' wurde zum Versand übergeben.', 0);
                } else {
                    $this->SendDebug('EmailNotification', 'SMTP_SendMailEx meldete einen Fehler für ' . $recipient . '.', 0);
                }
            } catch (Throwable $e) {
                $this->SendDebug('EmailNotification', 'Fehler beim E-Mail-Versand an ' . $recipient . ': ' . $e->getMessage(), 0);
            }
        }

        $this->SendDebug('EmailNotification', 'E-Mail-Versand abgeschlossen; erfolgreich übergebene Empfänger: ' . $successCount . ' von ' . count($recipients) . '.', 0);
    }

    /** Liefert den aktuellen Wert der überwachten Variable in gut lesbarer Form. */
    private function FormatVariableValue(int $variableID): string
    {
        $variable = IPS_GetVariable($variableID);
        switch ((int)$variable['VariableType']) {
            case VARIABLETYPE_BOOLEAN:
                return GetValueBoolean($variableID) ? 'Ein (TRUE)' : 'Aus (FALSE)';
            case VARIABLETYPE_INTEGER:
                return (string)GetValueInteger($variableID);
            case VARIABLETYPE_FLOAT:
                return rtrim(rtrim(number_format((float)GetValueFloat($variableID), 4, ',', '.'), '0'), ',');
            case VARIABLETYPE_STRING:
            default:
                return GetValueString($variableID);
        }
    }

    private function SendPushForRule(array $rule, int $alarmSince = 0, bool $escalation = false): void
    {
        $phaseFlag = $escalation ? 'PushOnEscalation' : 'PushOnFirst';
        if (!($rule[$phaseFlag] ?? false)) {
            $this->SendDebug('PushNotification', 'Push übersprungen: Versand für ' . ($escalation ? 'Eskalationen' : 'die Erstmeldung') . ' ist für diese Regel deaktiviert.', 0);
            return;
        }

        $visualizationIDs = is_array($rule['PushVisualizationIDs'] ?? null)
            ? $rule['PushVisualizationIDs']
            : $this->GetRulePushVisualizationIDs($rule);
        if (count($visualizationIDs) === 0) {
            $this->SendDebug('PushNotification', 'Push abgebrochen: Für diese Regel ist keine Push-Visualisierung ausgewählt.', 0);
            return;
        }
        $text = trim((string)($rule['PushText'] ?? ''));
        if ($text === '') {
            $this->SendDebug('PushNotification', 'Push abgebrochen: Der Push-Text dieser Bedingung ist leer.', 0);
            return;
        }

        // IP-Symcon begrenzt den Titel auf 32 und den Nachrichtentext auf 256 Zeichen.
        // Deshalb werden alle Alarmdetails kompakt zusammengestellt und lange Werte gekürzt,
        // damit auch Zeitstempel und aktueller Variablenwert in der Pushnachricht vorkommen.
        $title = 'Alarmmonitor';
        $sound = $this->NormalizePushSound((string)($rule['PushSound'] ?? 'alarm'));
        $variableID = (int)($rule['VariableID'] ?? 0);
        if ($variableID <= 0 || !IPS_VariableExists($variableID)) {
            $this->SendDebug('PushNotification', 'Push übersprungen: Die überwachte Variable existiert nicht mehr.', 0);
            return;
        }

        $pushText = $this->FormatNotificationAlarmText((string)($rule['PushText'] ?? ''), $escalation, $rule);
        $variableName = $this->TruncateText(IPS_GetName($variableID), 25);
        $condition = $this->ConditionName((string)($rule['Operator'] ?? '=='), (string)($rule['CompareValue'] ?? ''), ((int)($rule['CompareVariableID'] ?? 0) > 0), (int)($rule['CompareVariableID'] ?? 0));
        $condition = $this->TruncateText($condition, 24);
        $delay = (int)($rule['Delay'] ?? 0);
        $triggerTime = date('d.m.Y H:i:s', $alarmSince > 0 ? $alarmSince : time());
        $actualValue = $this->TruncateText($this->FormatVariableValue($variableID), 20);

        $text = implode("\n", [
            'Meldung: ' . $this->TruncateText($pushText, 35),
            'Variable: ' . $variableName . ' (ID ' . $variableID . ')',
            'Bedingung: ' . $condition,
            'Verzögerung: ' . $delay . ' s',
            'Zeitpunkt: ' . $triggerTime,
            'Aktueller Wert: ' . $actualValue
        ]);
        $text = $this->TruncateText($text, 256);

        foreach ($visualizationIDs as $visualizationID) {
            try {
                $instance = IPS_GetInstance($visualizationID);
                $moduleName = strtolower((string)($instance['ModuleInfo']['ModuleName'] ?? ''));
                $moduleID = strtoupper((string)($instance['ModuleInfo']['ModuleID'] ?? ''));

                // WebFront Configurator: Push an die mobilen Geräte dieser WebFront-Visualisierung.
                if (str_contains($moduleName, 'webfront') || $moduleID === '{3565B1F2-8F7B-4311-A4B6-1BF1D868F39E}') {
                    $result = WFC_PushNotification($visualizationID, $title, $text, $sound, 0);
                // Kachel-Visualisierung (Tile Visualization), verfügbar ab IP-Symcon 7.
                } elseif (str_contains($moduleName, 'kachel') || str_contains($moduleName, 'tile') || str_contains($moduleName, 'visualization') || str_contains($moduleName, 'visualisierung')) {
                    if (function_exists('VISU_PostNotificationEx')) {
                        $result = VISU_PostNotificationEx($visualizationID, $title, $text, '', $sound, 0);
                    } elseif (function_exists('VISU_PostNotification')) {
                        $result = VISU_PostNotification($visualizationID, $title, $text, 'Alert', 0);
                    } else {
                        $this->SendDebug('PushNotification', 'Push-Funktion der Kachel-Visualisierung ist für ID ' . $visualizationID . ' nicht verfügbar.', 0);
                        continue;
                    }
                } else {
                    $this->SendDebug('PushNotification', 'Instanz ID ' . $visualizationID . ' ist keine unterstützte Visualisierung; übersprungen.', 0);
                    continue;
                }

                if ($result === false) {
                    $this->SendDebug('PushNotification', 'Push-API meldete FALSE für Visualisierung ' . $visualizationID . '; Textlänge: ' . (function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text)) . ' Zeichen/Bytes.', 0);
                } else {
                    $this->SendDebug('PushNotification', 'Push-Versand an Visualisierung ' . $visualizationID . ' wurde von der API angenommen; Rückgabewert: ' . (string)$result, 0);
                }
            } catch (Throwable $e) {
                $this->SendDebug('PushNotification', 'Fehler beim Push-Versand an Visualisierung ' . $visualizationID . ': ' . $e->getMessage(), 0);
            }
        }
    }

    /** Kürzt UTF-8-Text auf eine maximale Zeichenanzahl. */
    private function TruncateText(string $text, int $maxChars): string
    {
        if ($maxChars < 0) {
            return '';
        }
        if (function_exists('mb_strlen') && function_exists('mb_substr')) {
            return mb_strlen($text, 'UTF-8') > $maxChars ? mb_substr($text, 0, $maxChars, 'UTF-8') : $text;
        }
        // Nicht per substr() nach Bytes kürzen: Das kann bei Umlauten/UTF-8 ein
        // ungültiges Zeichen am Ende erzeugen, wodurch die Push-API den Text ablehnen kann.
        if (function_exists('iconv_substr')) {
            $length = iconv_strlen($text, 'UTF-8');
            if ($length !== false && $length > $maxChars) {
                $truncated = iconv_substr($text, 0, $maxChars, 'UTF-8');
                if ($truncated !== false) {
                    return $truncated;
                }
            } elseif ($length !== false) {
                return $text;
            }
        }
        $characters = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($characters)) {
            return count($characters) > $maxChars ? implode('', array_slice($characters, 0, $maxChars)) : $text;
        }
        // Nur für bereits ungültige UTF-8-Eingaben als letzter Rückfall.
        return strlen($text) > $maxChars ? substr($text, 0, $maxChars) : $text;
    }

    /**
     * Begrenzt den konfigurierbaren Push-Ton auf die von IP-Symcon dokumentierten Werte.
     * Ein leerer String bedeutet den normalen Systemton.
     */
    private function NormalizePushSound(string $sound): string
    {
        $allowedSounds = ['', 'alarm', 'bell', 'boom', 'buzzer', 'connected', 'dark', 'digital', 'drums', 'duck', 'full', 'happy', 'horn', 'inception', 'kazoo', 'roll', 'siren', 'space', 'trickling', 'turn'];
        return in_array($sound, $allowedSounds, true) ? $sound : 'alarm';
    }

    private function GetRules(): array
    {
        $rules = json_decode($this->ReadPropertyString('Rules'), true);
        return is_array($rules) ? $rules : [];
    }

    private function StateKey(int $variableID, int $index): string
    {
        return $variableID . ':' . $index;
    }

    private function AlarmIdent(int $variableID, int $index): string
    {
        return 'Alarm_' . $variableID . '_' . $index;
    }

    private function EscalationIdent(int $variableID, int $index): string
    {
        return 'Escalation_' . $variableID . '_' . $index;
    }

    private function EnsureEscalationVariable(int $variableID, int $index, string $operator, string $compareValue, int $delay, string $alarmText, bool $useCompareVariable = false, int $compareVariableID = 0): int
    {
        $ident = $this->EscalationIdent($variableID, $index);
        $escalationID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        $conditionName = $this->ConditionName($operator, $compareValue, $useCompareVariable, $compareVariableID);
        $alarmText = trim($alarmText) !== '' ? trim($alarmText) : 'Alarm';
        $name = $alarmText . ' – Eskalation – Variable-ID: ' . $variableID . ' – ' . $conditionName . ' – Verzögerung: ' . $delay . ' s';

        // Bestehende boolesche Eskalationsvariable auf den neuen Integer-Typ migrieren.
        if ($escalationID !== false) {
            $variable = @IPS_GetVariable((int)$escalationID);
            if (!is_array($variable) || (int)($variable['VariableType'] ?? -1) !== 1) {
                IPS_DeleteVariable((int)$escalationID);
                $escalationID = false;
            }
        }

        if ($escalationID === false) {
            $escalationID = $this->RegisterVariableInteger($ident, $name, '', 1000 + $index);
            IPS_SetHidden($escalationID, false);
            SetValueInteger($escalationID, 0);
        } else {
            IPS_SetName($escalationID, $name);
        }
        return (int)$escalationID;
    }

    /** Setzt den Eskalationszähler zurück oder aktualisiert ihn auf einen definierten Wert. */
    private function SetEscalationCount(array $rule, int $count): void
    {
        $ident = (string)($rule['EscalationIdent'] ?? '');
        if ($ident === '') return;
        $escalationID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($escalationID !== false && IPS_VariableExists((int)$escalationID)) {
            $count = max(0, $count);
            if (GetValueInteger((int)$escalationID) !== $count) {
                SetValueInteger((int)$escalationID, $count);
            }
        }
    }

    /** Erhöht den Zähler bei jeder ausgelösten Eskalationsbenachrichtigung um eins. */
    private function IncrementEscalationCount(array $rule): void
    {
        $ident = (string)($rule['EscalationIdent'] ?? '');
        if ($ident === '') return;
        $escalationID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        if ($escalationID !== false && IPS_VariableExists((int)$escalationID)) {
            SetValueInteger((int)$escalationID, GetValueInteger((int)$escalationID) + 1);
        }
    }

    private function EnsureAlarmVariable(int $variableID, int $index, string $operator, string $compareValue, int $delay, string $alarmText, bool $useCompareVariable = false, int $compareVariableID = 0): int
    {
        $ident = $this->AlarmIdent($variableID, $index);
        $alarmID = @IPS_GetObjectIDByIdent($ident, $this->InstanceID);
        $sourceName = IPS_VariableExists($variableID) ? IPS_GetName($variableID) : ('Variable ' . $variableID);
        $conditionName = $this->ConditionName($operator, $compareValue, $useCompareVariable, $compareVariableID);
        $alarmText = trim($alarmText) !== '' ? trim($alarmText) : 'Alarm';
        $name = $alarmText . ' – Variable-ID: ' . $variableID . ' – ' . $conditionName . ' – Verzögerung: ' . $delay . ' s';
        if ($alarmID === false) {
            $alarmID = $this->RegisterVariableBoolean($ident, $name, '~Alert', $index + 1);
            IPS_SetHidden($alarmID, false);
        } else {
            IPS_SetName($alarmID, $name);
        }
        return (int)$alarmID;
    }

    private function ConditionName(string $operator, string $compareValue, bool $useCompareVariable = false, int $compareVariableID = 0): string
    {
        $labels = ['==' => 'Ist gleich', '!=' => 'Ist nicht gleich', '>=' => 'Ist größer gleich', '>' => 'Ist größer', '<' => 'Ist kleiner', '<=' => 'Ist kleiner gleich', 'CHANGED' => 'Variable verändert', 'UPDATED' => 'Variable aktualisiert'];
        $label = $labels[$operator] ?? $operator;
        if (in_array($operator, ['CHANGED', 'UPDATED'], true)) return $label;
        if ($useCompareVariable) {
            if ($compareVariableID > 0 && IPS_VariableExists($compareVariableID)) {
                $compareName = IPS_GetName($compareVariableID);
                $compareValueText = $this->FormatVariableValue($compareVariableID);
                return $label . ' Variable "' . $compareName . '" (' . $compareValueText . ')';
            }
            return $label . ' Vergleichsvariable fehlt';
        }
        return $label . ' ' . $compareValue;
    }

    /** Pro Regel nur einmal melden, wenn der Variablenmodus aktiv ist, aber keine gültige Vergleichsvariable vorliegt. */
    private function LogMissingCompareVariableOnce(string $stateKey, int $compareVariableID): void
    {
        $warnings = json_decode($this->GetBuffer('CompareVariableWarnings'), true);
        if (!is_array($warnings)) $warnings = [];
        $issue = $compareVariableID > 0
            ? 'Die ausgewählte Vergleichsvariable mit ID ' . $compareVariableID . ' existiert nicht mehr oder ist keine Variable.'
            : 'Es ist keine Vergleichsvariable ausgewählt.';
        if (($warnings[$stateKey] ?? '') !== $issue) {
            $this->SendDebug('Comparison', $issue . ' Die Bedingung wird nicht ausgewertet; der feste Vergleichswert wird nicht als Ersatz verwendet. Regel ' . $stateKey . '.', 0);
            $warnings[$stateKey] = $issue;
            $this->SetBuffer('CompareVariableWarnings', json_encode($warnings, JSON_UNESCAPED_UNICODE));
        }
    }

    /** Entfernt den Warnstatus, sobald die Vergleichsvariable wieder gültig ist oder der Variablenmodus ausgeschaltet wurde. */
    private function ClearMissingCompareVariableWarning(string $stateKey): void
    {
        $warnings = json_decode($this->GetBuffer('CompareVariableWarnings'), true);
        if (!is_array($warnings) || !array_key_exists($stateKey, $warnings)) return;
        unset($warnings[$stateKey]);
        $this->SetBuffer('CompareVariableWarnings', json_encode($warnings, JSON_UNESCAPED_UNICODE));
    }

    private function ConditionMatches(int $variableID, string $operator, string $compareValue, bool $useCompareVariable = false, int $compareVariableID = 0): bool
    {
        if (!IPS_VariableExists($variableID) || in_array($operator, ['CHANGED', 'UPDATED'], true)) return false;
        if ($useCompareVariable) {
            // Ausschließlich den Variablenwert verwenden; compareValue wird in diesem Zweig ignoriert.
            if ($compareVariableID <= 0 || !IPS_VariableExists($compareVariableID)) {
                return false;
            }
            $expectedInfo = IPS_GetVariable($compareVariableID);
            $expectedType = (int)$expectedInfo['VariableType'];
            if ($expectedType === VARIABLETYPE_BOOLEAN) {
                $expected = GetValueBoolean($compareVariableID) ? 1 : 0;
            } elseif ($expectedType === VARIABLETYPE_INTEGER || $expectedType === VARIABLETYPE_FLOAT) {
                $expected = GetValue($compareVariableID);
            } else {
                $expected = GetValueString($compareVariableID);
            }
        }

        $variable = IPS_GetVariable($variableID);
        $type = (int)$variable['VariableType'];
        if ($type === VARIABLETYPE_BOOLEAN) {
            $actual = GetValueBoolean($variableID) ? 1 : 0;
            if ($useCompareVariable) {
                if (is_string($expected)) {
                    $normalized = strtolower(trim($expected));
                    if (in_array($normalized, ['1', 'true', 'ja', 'yes', 'ein', 'on'], true)) $expected = 1;
                    elseif (in_array($normalized, ['0', 'false', 'nein', 'no', 'aus', 'off'], true)) $expected = 0;
                    elseif (is_numeric($normalized)) $expected = (float)$normalized;
                    else return false;
                }
                if (!is_numeric($expected)) return false;
                return $this->CompareValues($actual, $operator, (float)$expected);
            }
            $normalized = strtolower(trim($compareValue));
            $expectedConstant = in_array($normalized, ['1', 'true', 'ja', 'yes', 'ein', 'on'], true) ? 1 : 0;
            return $this->CompareValues($actual, $operator, $expectedConstant);
        }
        if ($type === VARIABLETYPE_INTEGER || $type === VARIABLETYPE_FLOAT) {
            $actual = GetValue($variableID);
            if ($useCompareVariable) {
                if (is_bool($expected)) $expected = $expected ? 1 : 0;
                if (!is_numeric($expected)) return false;
                return $this->CompareValues((float)$actual, $operator, (float)$expected);
            }
            if (!is_numeric(trim($compareValue))) return false;
            return $this->CompareValues((float)$actual, $operator, (float)$compareValue);
        }

        $actual = GetValueString($variableID);
        if ($operator !== '==' && $operator !== '!=') return false;
        if ($useCompareVariable) {
            if (is_bool($expected)) $expected = $expected ? '1' : '0';
            $expected = (string)$expected;
        } else {
            $expected = $compareValue;
        }
        return $operator === '==' ? $actual === $expected : $actual !== $expected;
    }

    private function CompareValues($actual, string $operator, $expected): bool
    {
        switch ($operator) {
            case '==': return $actual == $expected;
            case '!=': return $actual != $expected;
            case '>=': return $actual >= $expected;
            case '>': return $actual > $expected;
            case '<': return $actual < $expected;
            case '<=': return $actual <= $expected;
        }
        return false;
    }
}
