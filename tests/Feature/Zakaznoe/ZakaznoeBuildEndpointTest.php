<?php

namespace Tests\Feature\Zakaznoe;

use App\Jobs\ZakaznoeBuildJob;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

class ZakaznoeBuildEndpointTest extends TestCase
{
    private const KEY = 'test-key';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.meeting_application.api_key' => self::KEY,
            'zakaznoe.crm_url' => 'http://crm.test',
        ]);

        Queue::fake();
    }

    public function test_accepts_task_into_zakaznoe_queue(): void
    {
        $this->postTask($this->task())
            ->assertStatus(202)
            ->assertExactJson(['accepted' => true]);

        Queue::assertPushed(ZakaznoeBuildJob::class, fn (ZakaznoeBuildJob $job) => $job->connection === 'zakaznoe'
            && $job->queue === 'zakaznoe'
            && $job->tries === 1);
    }

    public function test_same_build_uuid_is_queued_once(): void
    {
        $task = $this->task();

        $this->postTask($task)->assertStatus(202);
        $this->postTask($task)->assertStatus(202)->assertJson(['duplicate' => true]);

        Queue::assertPushed(ZakaznoeBuildJob::class, 1);
    }

    public function test_requires_api_key(): void
    {
        $this->postJson('/api/zakaznoe-builds', $this->task())->assertStatus(401);
        $this->postTask($this->task(), 'wrong')->assertStatus(401);

        Queue::assertNothingPushed();
    }

    public function test_rejects_everything_when_key_not_configured(): void
    {
        config(['services.meeting_application.api_key' => '']);

        $this->postTask($this->task(), '')->assertStatus(401);
    }

    public function test_generate_route_requires_api_key(): void
    {
        $this->postJson('/api/meeting-applications/generate', ['meeting_application_id' => 1])->assertStatus(401);
    }

    public function test_jobs_route_requires_api_key(): void
    {
        $this->getJson('/api/generate-meeting-application-jobs')->assertStatus(401);
        $this->getJson('/api/generate-meeting-application-jobs', ['Authorization' => 'Bearer wrong'])->assertStatus(401);
    }

    public function test_rejects_unknown_top_level_field(): void
    {
        $this->postTask([...$this->task(), 'worker_version' => '1'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('worker_version');
    }

    public function test_rejects_unknown_nested_field(): void
    {
        $task = $this->task();
        $task['letters'][0]['recipient']['phone'] = '+7';

        $this->postTask($task)->assertStatus(422)->assertJsonValidationErrors('letters.0.recipient');
    }

    public function test_rejects_callback_outside_crm(): void
    {
        $this->postTask([...$this->task(), 'callback_url' => 'http://evil.test/api/mail-registry/zakaznoe-build/callback'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('callback_url');

        // Префикс домена не обманывает проверку
        $this->postTask([...$this->task(), 'callback_url' => 'http://crm.test.evil.test/callback'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('callback_url');
    }

    public function test_rejects_directory_with_parent_segments(): void
    {
        $task = $this->task();
        $task['storage']['directory'] = '/onb/a/../b';

        $this->postTask($task)->assertStatus(422)->assertJsonValidationErrors('storage.directory');
    }

    public function test_rejects_parts_larger_than_zakaznoe_session(): void
    {
        $this->postTask([...$this->task(), 'letters_per_part' => 51])
            ->assertStatus(422)
            ->assertJsonValidationErrors('letters_per_part');
    }

    private function postTask(array $task, string $key = self::KEY)
    {
        return $this->postJson('/api/zakaznoe-builds', $task, ['Authorization' => 'Bearer '.$key]);
    }

    private function task(): array
    {
        return [
            'mail_registry_id' => 34,
            'build_uuid' => (string) Str::uuid(),
            'callback_url' => 'http://crm.test/api/mail-registry/zakaznoe-build/callback',
            'arbitrator_id' => 5,
            'storage' => ['provider' => 'yandex_disk', 'directory' => '/onb/arb/procedures/proc/mail-registries/2026_09_12_34'],
            'letters_per_part' => 50,
            'registry' => ['name' => 'Реестр', 'date_departure' => '14.09.2026'],
            'sender' => ['name' => 'Иванов И. И.', 'address' => '620014, г. Екатеринбург, ул. Ленина, д. 1'],
            'settings' => ['letter_type' => 1, 'zuev' => true],
            'letters' => [[
                'letter_id' => 690,
                'number' => '120',
                'recipient' => [
                    'recipient_type' => 1, 'org_name' => 'ООО «Ромашка»', 'inn' => '6600000000', 'kpp' => '660001000',
                    'lastname' => null, 'firstname' => null, 'middlename' => null,
                    'address' => '620000, г. Екатеринбург, ул. Малышева, д. 5',
                ],
                'files' => [[
                    'id' => 40, 'name' => 'Запрос.pdf', 'size' => 1000, 'provider' => 'yandex_disk', 'remote_path' => '/onb/x/40.pdf',
                ]],
            ]],
        ];
    }
}
