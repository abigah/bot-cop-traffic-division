<?php

namespace Abigah\BotCopTrafficDivision\Livewire\Concerns;

use Abigah\BotCopTrafficDivision\Models\Monitor;
use Abigah\BotCopTrafficDivision\Services\MonitorQuery;
use Illuminate\Validation\Rule;

/**
 * Creating and editing monitors from the site page.
 *
 * Every write goes back through the scoped query rather than trusting the id
 * that came from the browser, so an owner can only ever edit their own — the
 * same rule the read paths follow, for the same reason.
 */
trait ManagesMonitors
{
    public bool $showMonitorForm = false;

    public ?int $editingMonitorId = null;

    /** @var array<string, mixed> */
    public array $monitorForm = [];

    public function newMonitor(): void
    {
        $this->editingMonitorId = null;
        $this->monitorForm = $this->blankMonitor();
        $this->resetValidation();
        $this->showMonitorForm = true;
    }

    public function editMonitor(int $monitor): void
    {
        $model = MonitorQuery::forCurrentOwner()->findMonitor($monitor);

        if ($model === null || (string) $model->site_id !== (string) $this->siteId) {
            return;
        }

        $this->editingMonitorId = $model->getKey();
        $this->monitorForm = [
            'url' => (string) $model->url,
            'name' => (string) ($model->name ?? ''),
            'method' => strtoupper((string) ($model->uptime_check_method ?: 'get')),
            'interval' => (int) $model->uptime_check_interval_in_minutes,
            'critical' => (bool) $model->critical,
            'enabled' => (bool) $model->uptime_check_enabled,
            'look_for_string' => (string) $model->look_for_string,
            'fail_for_string' => (string) $model->fail_for_string,
            'payload' => (string) ($model->uptime_check_payload ?? ''),
            'certificate' => (bool) $model->certificate_check_enabled,
            'domain' => (bool) $model->domain_expiry_check_enabled,
        ];
        $this->resetValidation();
        $this->showMonitorForm = true;
    }

    public function saveMonitor(): void
    {
        $data = $this->validate($this->monitorRules())['monitorForm'];

        $query = MonitorQuery::forCurrentOwner();

        $attributes = [
            'url' => $data['url'],
            'name' => $data['name'] ?: null,
            'uptime_check_method' => strtolower($data['method']),
            'uptime_check_interval_in_minutes' => (int) $data['interval'],
            'critical' => (bool) $data['critical'],
            'uptime_check_enabled' => (bool) $data['enabled'],
            'look_for_string' => $data['look_for_string'] ?? '',
            'fail_for_string' => $data['fail_for_string'] ?? '',
            'uptime_check_payload' => $data['payload'] ?: null,
            'certificate_check_enabled' => (bool) $data['certificate'],
            'domain_expiry_check_enabled' => (bool) $data['domain'],
        ];

        if ($this->editingMonitorId !== null) {
            $model = $query->findMonitor($this->editingMonitorId);

            if ($model !== null && (string) $model->site_id === (string) $this->siteId) {
                $model->forceFill($attributes)->save();
            }
        } else {
            $site = $query->findSite($this->siteId);

            Monitor::create([
                ...$attributes,
                'site_id' => $site->getKey(),
                'owner_id' => $query->ownerKey(),
            ]);
        }

        $this->showMonitorForm = false;
        $this->editingMonitorId = null;
    }

    public function toggleMonitorPaused(int $monitor): void
    {
        $model = MonitorQuery::forCurrentOwner()->findMonitor($monitor);

        if ($model === null || (string) $model->site_id !== (string) $this->siteId) {
            return;
        }

        $model->forceFill(['uptime_check_enabled' => ! $model->uptime_check_enabled])->save();
    }

    public function deleteMonitor(int $monitor): void
    {
        $model = MonitorQuery::forCurrentOwner()->findMonitor($monitor);

        if ($model === null || (string) $model->site_id !== (string) $this->siteId) {
            return;
        }

        // Its checks, incidents and aggregates go with it: this is a deletion,
        // not an archive, and half-deleted history is worse than none.
        $model->delete();
    }

    /** @return array<string, mixed> */
    protected function blankMonitor(): array
    {
        return [
            'url' => '',
            'name' => '',
            'method' => 'GET',
            'interval' => $this->minimumInterval(),
            'critical' => true,
            'enabled' => true,
            'look_for_string' => '',
            'fail_for_string' => '',
            'payload' => '',
            'certificate' => false,
            'domain' => false,
        ];
    }

    /**
     * The shortest interval this form offers, mirrored from the prober's
     * per-tenant minimum. The prober is authoritative and clamps anyway; this
     * is only so the form does not offer something that will be quietly
     * overruled.
     */
    public function minimumInterval(): int
    {
        return (int) config('monitoring.uptime.minimum_interval_minutes', 5);
    }

    /** @return array<string, mixed> */
    protected function monitorRules(): array
    {
        return [
            'monitorForm.url' => ['required', 'url', 'starts_with:http://,https://'],
            'monitorForm.name' => ['nullable', 'string', 'max:255'],
            'monitorForm.method' => ['required', Rule::in(['GET', 'HEAD', 'POST'])],
            'monitorForm.interval' => ['required', 'integer', 'min:'.$this->minimumInterval(), 'max:10080'],
            'monitorForm.critical' => ['boolean'],
            'monitorForm.enabled' => ['boolean'],
            'monitorForm.look_for_string' => ['nullable', 'string', 'max:255'],
            'monitorForm.fail_for_string' => ['nullable', 'string', 'max:255'],
            'monitorForm.payload' => ['nullable', 'string'],
            'monitorForm.certificate' => ['boolean'],
            'monitorForm.domain' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    protected function monitorValidationAttributes(): array
    {
        return [
            'monitorForm.url' => 'URL',
            'monitorForm.interval' => 'interval',
            'monitorForm.look_for_string' => 'look-for string',
            'monitorForm.fail_for_string' => 'fail-for string',
        ];
    }
}
