<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\DocumentSourceType;
use App\Models\DocumentSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DocumentSource>
 */
class DocumentSourceFactory extends Factory
{
    protected $model = DocumentSource::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'type' => DocumentSourceType::WINDOWS_LOCAL,
            'path' => sys_get_temp_dir(),
            'enabled' => true,
            'scan_interval_minutes' => 60,
        ];
    }

    /** Point the source at a real directory the test has created. */
    public function atPath(string $path): static
    {
        return $this->state(fn (array $attributes) => ['path' => $path]);
    }

    public function type(DocumentSourceType $type): static
    {
        return $this->state(fn (array $attributes) => ['type' => $type]);
    }

    public function upload(): static
    {
        return $this->state(fn (array $attributes) => [
            'name' => 'Manual Upload',
            'type' => DocumentSourceType::UPLOAD,
            'path' => null,
        ]);
    }

    public function sharePoint(): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => DocumentSourceType::SHAREPOINT,
            'path' => null,
            'configuration' => [
                'site_id' => fake()->uuid(),
                'drive_id' => fake()->uuid(),
                'folder_path' => '/sites/DocumentControl/Documents',
            ],
        ]);
    }

    public function disabled(): static
    {
        return $this->state(fn (array $attributes) => ['enabled' => false]);
    }
}
