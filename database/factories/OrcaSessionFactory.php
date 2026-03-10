<?php

namespace MakeDev\Orca\Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use MakeDev\Orca\Enums\OrcaSessionStatus;
use MakeDev\Orca\Enums\OrcaSessionType;
use MakeDev\Orca\Models\OrcaSession;

class OrcaSessionFactory extends Factory
{
    protected $model = OrcaSession::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_type' => OrcaSessionType::Command,
            'command' => 'echo "hello world"',
            'status' => OrcaSessionStatus::Pending,
            'output' => null,
            'exit_code' => null,
            'pid' => null,
            'started_at' => null,
            'completed_at' => null,
        ];
    }

    public function running(): static
    {
        return $this->state([
            'status' => OrcaSessionStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state([
            'status' => OrcaSessionStatus::Completed,
            'output' => 'hello world',
            'exit_code' => 0,
            'started_at' => now()->subSeconds(2),
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state([
            'status' => OrcaSessionStatus::Failed,
            'output' => 'command not found',
            'exit_code' => 127,
            'started_at' => now()->subSeconds(1),
            'completed_at' => now(),
        ]);
    }

    public function claude(): static
    {
        return $this->state([
            'session_type' => OrcaSessionType::Claude,
            'command' => null,
            'prompt' => 'Help me fix the login bug',
            'permission_mode' => 'plan',
        ]);
    }

    public function awaitingInput(): static
    {
        return $this->state([
            'status' => OrcaSessionStatus::AwaitingInput,
            'started_at' => now()->subSeconds(5),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state([
            'status' => OrcaSessionStatus::Cancelled,
            'started_at' => now()->subSeconds(5),
            'completed_at' => now(),
        ]);
    }

    public function poppedOut(): static
    {
        return $this->state([
            'status' => OrcaSessionStatus::PoppedOut,
            'popped_out_at' => now(),
            'started_at' => now(),
        ]);
    }

    public function skipPermissions(): static
    {
        return $this->state([
            'skip_permissions' => true,
            'permission_mode' => null,
        ]);
    }

    public function withUser(?User $user = null): static
    {
        return $this->state(function () use ($user) {
            $user ??= User::factory()->create();

            return [
                'user_id' => $user->id,
                'user_email' => $user->email,
            ];
        });
    }

    public function withRouteInfo(string $handler = 'App\\Http\\Controllers\\DashboardController@index', string $type = 'controller', ?string $name = 'dashboard'): static
    {
        return $this->state([
            'route_handler' => $handler,
            'route_handler_type' => $type,
            'route_name' => $name,
        ]);
    }
}
