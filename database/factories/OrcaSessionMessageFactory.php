<?php

namespace MakeDev\Orca\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use MakeDev\Orca\Models\OrcaSession;
use MakeDev\Orca\Models\OrcaSessionMessage;

class OrcaSessionMessageFactory extends Factory
{
    protected $model = OrcaSessionMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'session_id' => OrcaSession::factory(),
            'direction' => 'outbound',
            'type' => 'text',
            'content' => ['text' => $this->faker->sentence()],
            'metadata' => null,
            'delivered_at' => null,
        ];
    }

    public function outbound(): static
    {
        return $this->state([
            'direction' => 'outbound',
        ]);
    }

    public function inbound(): static
    {
        return $this->state([
            'direction' => 'inbound',
        ]);
    }

    public function question(): static
    {
        return $this->state([
            'direction' => 'outbound',
            'type' => 'question',
            'content' => ['text' => 'Which approach should we use?'],
            'metadata' => [
                'options' => ['Option A', 'Option B'],
            ],
        ]);
    }

    public function answer(): static
    {
        return $this->state([
            'direction' => 'inbound',
            'type' => 'answer',
            'content' => ['text' => 'Option A'],
        ]);
    }

    public function permission(): static
    {
        return $this->state([
            'direction' => 'outbound',
            'type' => 'permission',
            'content' => ['text' => 'Allow file edit?'],
            'metadata' => [
                'tool' => 'Edit',
                'file' => '/path/to/file.php',
            ],
        ]);
    }

    public function toolUse(): static
    {
        return $this->state([
            'direction' => 'outbound',
            'type' => 'tool_use',
            'content' => ['tool' => 'Read', 'args' => ['file_path' => '/path/to/file']],
            'metadata' => ['tool' => 'Read'],
        ]);
    }

    public function delivered(): static
    {
        return $this->state([
            'delivered_at' => now(),
        ]);
    }
}
