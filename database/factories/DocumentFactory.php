<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\AnalysisStatus;
use App\Enums\DocumentType;
use App\Models\Document;
use App\Models\DocumentSource;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Document>
 */
class DocumentFactory extends Factory
{
    protected $model = Document::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $fileName = fake()->unique()->words(3, true).'.docx';

        return [
            'document_source_id' => DocumentSource::factory(),
            'source_type' => fn (array $attributes) => DocumentSource::find($attributes['document_source_id'])?->type,
            'source_item_id' => hash('sha256', fake()->unique()->uuid()),
            'file_path' => 'SOP/'.$fileName,
            'file_name' => $fileName,
            'original_file_name' => $fileName,
            'extension' => 'docx',
            'file_size' => fake()->numberBetween(10_000, 5_000_000),
            'document_type' => fake()->randomElement(DocumentType::cases()),
            'document_title' => fake()->sentence(4),
            'file_hash' => hash('sha256', fake()->uuid()),
            'source_last_modified_at' => now()->subDays(fake()->numberBetween(1, 200)),
            'analysis_status' => AnalysisStatus::PENDING,
            'is_active' => true,
        ];
    }

    public function status(AnalysisStatus $status): static
    {
        return $this->state(fn (array $attributes) => [
            'analysis_status' => $status,
            'last_analyzed_at' => $status->isTerminal() ? now() : null,
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
            'missing_since' => now(),
        ]);
    }
}
