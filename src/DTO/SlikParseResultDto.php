<?php

namespace App\DTO;

class SlikParseResultDto implements \JsonSerializable {
    public ?SlikMetadataDto $metadata = null;
    public ?SlikSummaryDto $ringkasan = null;
    /** @var SlikFacilityDto[] */
    public array $fasilitas = [];
    public array $data_pokok = [];
    public array $validation = [
        'identity_match' => false
    ];

    public function __construct(
        ?SlikMetadataDto $metadata = null,
        ?SlikSummaryDto $ringkasan = null,
        array $fasilitas = [],
        array $data_pokok = [],
        array $validation = ['identity_match' => false]
    ) {
        $this->metadata = $metadata ?? new SlikMetadataDto();
        $this->ringkasan = $ringkasan ?? new SlikSummaryDto();
        $this->fasilitas = $fasilitas;
        $this->data_pokok = $data_pokok;
        $this->validation = $validation;
    }

    public function jsonSerialize(): array {
        return [
            'metadata' => $this->metadata,
            'ringkasan' => $this->ringkasan,
            'fasilitas' => $this->fasilitas,
            'data_pokok' => $this->data_pokok,
            'validation' => $this->validation
        ];
    }
}
