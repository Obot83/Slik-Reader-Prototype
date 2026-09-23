<?php

namespace App\Service;

use App\DTO\SlikMetadataDto;
use App\DTO\SlikSummaryDto;
use App\DTO\SlikFacilityDto;
use App\DTO\SlikParseResultDto;

class SlikParserService {

    private const INDO_MONTHS = [
        'januari' => 1, 'februari' => 2, 'maret' => 3, 'april' => 4,
        'mei' => 5, 'juni' => 6, 'juli' => 7, 'agustus' => 8,
        'september' => 9, 'oktober' => 10, 'november' => 11, 'desember' => 12,
    ];

    private const KUALITAS_LABEL = [
        1 => '1 - Lancar',
        2 => '2 - Dalam Perhatian Khusus',
        3 => '3 - Kurang Lancar',
        4 => '4 - Diragukan',
        5 => '5 - Macet',
    ];

    /**
     * Extract raw text from PDF file.
     * Primary path uses smalot/pdfparser (handles real-world PDFs with
     * embedded/subset fonts, CID/Type0 text, FlateDecode streams, etc).
     * Falls back to a hand-rolled stream decoder for malformed/minimal
     * PDFs (e.g. synthetic test fixtures) that smalot rejects.
     */
    public function extractTextFromPdf(string $pdfPath): string {
        if (!file_exists($pdfPath)) {
            return '';
        }

        try {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($pdfPath);
            $text = $pdf->getText();
            if (!empty(trim($text))) {
                return $text;
            }
        } catch (\Throwable $e) {
            // fall through to legacy extraction
        }

        return $this->extractTextFromPdfLegacy($pdfPath);
    }

    private function extractTextFromPdfLegacy(string $pdfPath): string {
        $content = file_get_contents($pdfPath);
        if ($content === false || empty($content)) {
            return '';
        }

        $text = '';

        if (preg_match_all('/stream[\r\n]+(.*?)[\r\n]+endstream/s', $content, $matches)) {
            foreach ($matches[1] as $stream) {
                $uncompressed = @gzuncompress($stream);
                if ($uncompressed === false) {
                    $uncompressed = @gzinflate($stream);
                }
                if ($uncompressed === false) {
                    $uncompressed = $stream;
                }
                $extracted = $this->extractTextFromStream($uncompressed);
                if (!empty($extracted)) {
                    $text .= " " . $extracted;
                }
            }
        }

        if (empty(trim($text))) {
            $text = $this->extractTextFromStream($content);
        }

        $cleanedText = preg_replace('/\s+/', ' ', $text);
        return trim($cleanedText);
    }

    private function extractTextFromStream(string $stream): string {
        $extracted = '';

        if (preg_match_all('/BT[\r\n\s]+(.*?)[\r\n\s]+ET/s', $stream, $btMatches)) {
            foreach ($btMatches[1] as $btBlock) {
                if (preg_match_all('/\((.*?)\)\s*Tj/s', $btBlock, $tjMatches)) {
                    foreach ($tjMatches[1] as $t) {
                        $extracted .= ' ' . $this->unescapePdfString($t);
                    }
                }
                if (preg_match_all('/\[(.*?)\]\s*TJ/s', $btBlock, $arrayMatches)) {
                    foreach ($arrayMatches[1] as $arr) {
                        if (preg_match_all('/\((.*?)\)/s', $arr, $partMatches)) {
                            foreach ($partMatches[1] as $pt) {
                                $extracted .= $this->unescapePdfString($pt);
                            }
                            $extracted .= ' ';
                        }
                    }
                }
                if (preg_match_all('/\((.*?)\)\s*[\'"]/s', $btBlock, $quoteMatches)) {
                    foreach ($quoteMatches[1] as $t) {
                        $extracted .= " " . $this->unescapePdfString($t);
                    }
                }
            }
        }

        return $extracted;
    }

    private function unescapePdfString(string $str): string {
        $str = str_replace(['\\\\', '\\(', '\\)'], ['\\', '(', ')'], $str);
        $str = str_replace(['\\n', '\\r', '\\t'], [" ", " ", " "], $str);
        return $str;
    }

    /**
     * Parse text into structured SlikParseResultDto
     */
    public function parsePdf(string $pdfPath, ?string $expectedNik = null): SlikParseResultDto {
        $rawText = $this->extractTextFromPdf($pdfPath);

        $metadata = $this->extractMetadata($rawText);
        $ringkasan = $this->extractSummary($rawText);
        $fasilitas = $this->extractFacilities($rawText);
        $dataPokok = $this->extractDataPokok($metadata, $fasilitas);

        $identityMatch = false;
        if (!empty($expectedNik) && !empty($metadata->no_identitas)) {
            $cleanExpected = preg_replace('/[^0-9]/', '', $expectedNik);
            $cleanParsed = preg_replace('/[^0-9]/', '', $metadata->no_identitas);
            $identityMatch = ($cleanExpected === $cleanParsed);
        }

        return new SlikParseResultDto(
            $metadata,
            $ringkasan,
            $fasilitas,
            $dataPokok,
            ['identity_match' => $identityMatch]
        );
    }

    // ------------------------------------------------------------------
    // Metadata
    // ------------------------------------------------------------------

    private function extractMetadata(string $text): SlikMetadataDto {
        $meta = $this->extractMetadataOjkLayout($text);
        $legacy = $this->extractMetadataLabelColon($text);

        foreach (get_object_vars($legacy) as $key => $value) {
            if (empty($meta->$key) && !empty($value)) {
                $meta->$key = $value;
            }
        }

        return $meta;
    }

    /**
     * Extraction tuned to the real OJK iDeb report layout (smalot text
     * order: repeating page footer "Label...Value|Label...Value", and
     * a "NAMA NIK / <nik> <gender> / [<npwp>] <tempat> / <tgl lahir>"
     * block per pelapor entry in "Data Pokok Debitur").
     */
    private function extractMetadataOjkLayout(string $text): SlikMetadataDto {
        $meta = new SlikMetadataDto();

        if (preg_match('/Nomor\s*Laporan\s*([0-9A-Za-z\/\.\-]+)\s*\|\s*Operator/u', $text, $m)) {
            $meta->no_laporan = trim($m[1]);
        }

        if (preg_match('/Tanggal\s*Permintaan\s*([0-9]{1,2}\s+[A-Za-z]+\s+[0-9]{4}(?:\s+[0-9:]+)?)\s*\|\s*Kode\s*Ref/u', $text, $m)) {
            $meta->tanggal_permintaan = $this->parseIndoDateTime(trim($m[1]));
        }

        if (preg_match('/Posisi\s*Data\s*Terakhir\s*([^\|]+?)\s*\|\s*LJK/u', $text, $m)) {
            $meta->posisi_data_terakhir = $this->parseIndoDateOrMonth(trim($m[1]));
        }

        $pattern = '/([A-Z][A-Z\.\'\s]{1,60}?)\s*NIK\s*\/\s*\n?\s*([0-9]{16})\s*\n?\s*' .
            '(LAKI-LAKI|PEREMPUAN)\s*\/\s*\n?\s*(?:([0-9]{15,16})\s*\n?\s*)?' .
            '([A-Za-z\s]+?)\s*\/\s*\n?\s*([0-9]{1,2}\s+[A-Za-z]+\s+[0-9]{4})/u';
        if (preg_match($pattern, $text, $m)) {
            $meta->nama_debitur = trim(preg_replace('/\s+/', ' ', $m[1]));
            $meta->no_identitas = trim($m[2]);
            $meta->jenis_kelamin = trim($m[3]);
            $meta->npwp = !empty($m[4]) ? trim($m[4]) : null;
            $meta->tempat_lahir = trim(preg_replace('/\s+/', ' ', $m[5]));
            $meta->tanggal_lahir = $this->parseIndoDateOrMonth(trim($m[6]));
        }

        if (preg_match('/ALAMAT\.\s*([^\n]+(?:\n[^\n]+)?)/u', $text, $m)) {
            $meta->alamat = trim(preg_replace('/\s+/', ' ', $m[1]));
        }

        return $meta;
    }

    /**
     * Legacy extraction for simple "Label: Value" adjacent text, used by
     * synthetic/manual PDF fixtures. Left as a fallback so those keep
     * working; does not fabricate placeholder values when a field is
     * genuinely absent from the document.
     */
    private function extractMetadataLabelColon(string $text): SlikMetadataDto {
        $meta = new SlikMetadataDto();

        if (preg_match('/(?:No(?:mor)?\.?\s*Laporan|No\.\s*IDEB|Nomor\s*Permintaan)[:\s]+([A-Za-z0-9\/\.\-_]+)/i', $text, $m)) {
            $meta->no_laporan = trim($m[1]);
        } elseif (preg_match('/([0-9]{3,}\/IDEB\/[A-Za-z0-9\/\-_]+)/i', $text, $m)) {
            $meta->no_laporan = trim($m[1]);
        }

        if (preg_match('/(?:Tanggal\s*Permintaan|Tgl\s*Permintaan)[:\s]+([0-9]{4}-[0-9]{2}-[0-9]{2}|[0-9]{2}[-\/][0-9]{2}[-\/][0-9]{4})/i', $text, $m)) {
            $meta->tanggal_permintaan = trim($m[1]);
        }

        if (preg_match('/(?:No(?:mor)?\.?\s*Identitas|NIK|No\.?\s*KTP)[:\s]+([0-9]{16})/i', $text, $m)) {
            $meta->no_identitas = trim($m[1]);
        } elseif (preg_match('/\b([0-9]{16})\b/', $text, $m)) {
            $meta->no_identitas = trim($m[1]);
        }

        if (preg_match('/(?:Nama\s*Debitur|Nama\s*Lengkap|Nama)[:\s]+([A-Z\s\.\',]+?)(?=\s+(?:No\.?\s*Identitas|NIK|Tempat|Tanggal|Jenis|NPWP|Alamat|Posisi|Nomor|\-|$))/i', $text, $m)) {
            $meta->nama_debitur = trim($m[1]);
        }

        if (preg_match('/(?:Tempat\s*Lahir)[:\s]+([A-Za-z\s]+?)(?=\s+(?:Tanggal|Jenis|NPWP|Alamat|\-|$))/i', $text, $m)) {
            $meta->tempat_lahir = trim($m[1]);
        }
        if (preg_match('/(?:Tanggal\s*Lahir)[:\s]+([0-9]{4}-[0-9]{2}-[0-9]{2}|[0-9]{2}[-\/][0-9]{2}[-\/][0-9]{4})/i', $text, $m)) {
            $meta->tanggal_lahir = trim($m[1]);
        }

        if (preg_match('/(?:Jenis\s*Kelamin)[:\s]+(LAKI-LAKI|PEREMPUAN|PRIA|WANITA)/i', $text, $m)) {
            $meta->jenis_kelamin = strtoupper(trim($m[1]));
        }

        if (preg_match('/(?:NPWP)[:\s]+([0-9\.\-]+)/i', $text, $m)) {
            $meta->npwp = trim($m[1]);
        }

        if (preg_match('/(?:Alamat)[:\s]+([A-Za-z0-9\s\.\,\-\/]+?)(?=\s+(?:Posisi|Ringkasan|Plafon|Baki|Kualitas|\-|$))/i', $text, $m) && strpos($m[1], "\n") === false) {
            $meta->alamat = trim($m[1]);
        }

        if (preg_match('/(?:Posisi\s*Data\s*Terakhir|Posisi\s*Data)[:\s]+([0-9]{4}-[0-9]{2}-[0-9]{2}|[0-9]{2}[-\/][0-9]{2}[-\/][0-9]{4})/i', $text, $m)) {
            $meta->posisi_data_terakhir = trim($m[1]);
        }

        return $meta;
    }

    // ------------------------------------------------------------------
    // Ringkasan / Summary
    // ------------------------------------------------------------------

    private function extractSummary(string $text): SlikSummaryDto {
        $summary = $this->extractSummaryOjkLayout($text);

        if ($summary->plafon_efektif_total <= 0 && $summary->baki_debet_total <= 0) {
            $legacy = $this->extractSummaryLabelColon($text);
            $summary->plafon_efektif_total = $legacy->plafon_efektif_total ?: $summary->plafon_efektif_total;
            $summary->baki_debet_total = $legacy->baki_debet_total ?: $summary->baki_debet_total;
            if (!empty($legacy->kredit_bank_umum)) {
                $summary->kredit_bank_umum = $legacy->kredit_bank_umum;
            }
            if ($legacy->kualitas_terburuk !== '1 - Lancar') {
                $summary->kualitas_terburuk = $legacy->kualitas_terburuk;
            }
        }

        if ($summary->plafon_efektif_total > 0) {
            $summary->utilisasi_plafon = round($summary->baki_debet_total / $summary->plafon_efektif_total, 4);
        }

        return $summary;
    }

    /**
     * Extraction from the "Ringkasan Fasilitas" box. The values are
     * concatenated without delimiters (e.g. "105.238.462,000,00 0,00
     * 0,00105.238.462,002 / Agustus 2025"), but the Total column is
     * always the LAST currency figure on the line, immediately followed
     * by the worst-quality "N / Month Year" marker.
     */
    private function extractSummaryOjkLayout(string $text): SlikSummaryDto {
        $summary = new SlikSummaryDto();

        if (preg_match('/Plafon\s*Efektif([^\n]+)/u', $text, $m)) {
            $line = $m[1];
            preg_match_all('/[0-9]{1,3}(?:\.[0-9]{3})*,[0-9]{2}/', $line, $nums);
            if (!empty($nums[0])) {
                $summary->plafon_efektif_total = $this->parseIndoNumber(end($nums[0]));
            }
            if (preg_match('/([1-5])\s*\/\s*([A-Za-z]+\s+[0-9]{4})/u', $line, $km)) {
                $summary->kualitas_terburuk = self::KUALITAS_LABEL[(int)$km[1]] ?? $summary->kualitas_terburuk;
                $summary->bulan_kualitas_terburuk = $this->parseIndoMonthYearToYm($km[2]);
            }
        }

        if (preg_match('/Baki\s*Debet([^\n]+)/u', $text, $m)) {
            preg_match_all('/[0-9]{1,3}(?:\.[0-9]{3})*,[0-9]{2}/', $m[1], $nums);
            if (!empty($nums[0])) {
                $summary->baki_debet_total = $this->parseIndoNumber(end($nums[0]));
            }
        }

        // Extract "Jumlah Kreditur" (creditor counts) from Ringkasan Fasilitas box.
        // Try multiple anchors since PDF layout may vary:
        // 1. Start from "Ringkasan Fasilitas" label
        // 2. Fallback: start from "Bank Umum" itself if Ringkasan label is separated
        // 3. Fallback: search whole Plafon Efektif context
        $ringkasan_block = null;

        if (preg_match('/Ringkasan\s*Fasilitas(.{0,2000}?)(?=Pelapor\s*Cabang|Fasilitas Per|$)/su', $text, $m)) {
            $ringkasan_block = $m;
        } elseif (preg_match('/Bank\s*Umum(.{0,800}?)(?=Pelapor\s*Cabang|Fasilitas Per|$)/su', $text, $m)) {
            // Fallback: search around Bank Umum directly
            $ringkasan_block = $m;
        } elseif (preg_match('/Plafon\s*Efektif(.{0,1500}?)(?=Pelapor\s*Cabang|Fasilitas Per|$)/su', $text, $m)) {
            // Fallback: include context around Plafon Efektif (which is in Ringkasan box)
            $ringkasan_block = $m;
        }

        if ($ringkasan_block) {
            // $ringkasan_block[0] is full match, [1] is capture group (text after anchor)
            $block = $ringkasan_block[1];

            // Table layout in PDF has numbers scattered before/after labels.
            // Pattern: standalone digit followed by label, or label followed by digit.
            // Extract ALL standalone digits first, then map by context.

            // 1. Extract Bank Umum: almost always directly adjacent "Bank Umum9" or "Bank Umum 9"
            if (preg_match('/Bank\s*Umum[\s\t]*(\d{1,2})/iu', $block, $bu)) {
                $summary->kredit_bank_umum = (int)$bu[1];
            }

            // 2. Extract BPR/BPRS: look for "BPR / BPRS" followed by digit within next 50 chars
            // or look for pattern like "1 Garansi" where 1 comes before Garansi label
            if (preg_match('/BPR\s*\/\s*BPRS[\s\t\n]*(\d{1,2})/iu', $block, $bpr)) {
                $summary->kredit_bpr = (int)$bpr[1];
            } elseif (preg_match('/(\d{1,2})\s+Garansi\s+Yang/iu', $block, $bpr_alt)) {
                // Fallback: digit before "Garansi Yang Diberikan" label = BPR count
                $summary->kredit_bpr = (int)$bpr_alt[1];
            }

            // 3. Extract Lembaga Pembiayaan: in table layout, "4" appears before "Fasilitas" label
            // Pattern: digit followed by newline/spaces, then "Fasilitas", eventually "Lembaga"
            if (preg_match('/(\d{1,2})\s*\n\s*Fasilitas[\s\S]*?Lembaga/iu', $block, $lp)) {
                $summary->kredit_lp = (int)$lp[1];
            } elseif (preg_match('/Lembaga[\s\t\n]*(?:Pembiayaan)?[\s\t\n]*(\d{1,2})/iu', $block, $lp_alt)) {
                // Fallback: "Lembaga Pembiayaan" directly followed by digit
                $summary->kredit_lp = (int)$lp_alt[1];
            }

            // 4. Extract Lainnya: look for "Lainnya" followed by digit
            // In the text stream "Bank Umum9  0Jumlah Kreditur", the "0" before "Jumlah" is Lainnya
            if (preg_match('/Lainnya[\s\t\n]*(\d{1,2})/iu', $block, $ln)) {
                $summary->kredit_lainnya = (int)$ln[1];
            } elseif (preg_match('/Bank\s*Umum\s*\d+\s*(\d{1,2})\s*Jumlah\s*Kreditur/iu', $block, $ln_alt)) {
                // Fallback: the digit between Bank Umum value and "Jumlah Kreditur" = Lainnya
                $summary->kredit_lainnya = (int)$ln_alt[1];
            }
        }

        return $summary;
    }

    private function extractSummaryLabelColon(string $text): SlikSummaryDto {
        $summary = new SlikSummaryDto();

        if (preg_match('/(?:Plafon\s*Efektif\s*Total|Total\s*Plafon)[:\s]*Rp?\.?\s*([0-9\.,]+)/i', $text, $m)) {
            $summary->plafon_efektif_total = $this->parseIndoNumber($m[1]);
        }

        if (preg_match('/(?:Baki\s*Debet\s*Total|Total\s*Baki\s*Debet)[:\s]*Rp?\.?\s*([0-9\.,]+)/i', $text, $m)) {
            $summary->baki_debet_total = $this->parseIndoNumber($m[1]);
        }

        if (preg_match('/\b(5\s*-\s*Macet|4\s*-\s*Diragukan|3\s*-\s*Kurang\s*Lancar|2\s*-\s*DPK|1\s*-\s*Lancar)\b/i', $text, $m)) {
            $summary->kualitas_terburuk = trim($m[1]);
        } elseif (preg_match('/(?:Kualitas\s*Terburuk|Kolektibilitas\s*Terburuk)[:\s]*([1-5]\s*-\s*[A-Za-z]+)/i', $text, $m)) {
            $summary->kualitas_terburuk = trim($m[1]);
        }

        if (preg_match('/(?:Bulan\s*Kualitas\s*Terburuk)[:\s]*([0-9]{4}-[0-9]{2})/i', $text, $m)) {
            $summary->bulan_kualitas_terburuk = trim($m[1]);
        }

        if (preg_match('/Kredit\s*Bank\s*Umum[:\s]*([0-9]+)/i', $text, $m)) {
            $summary->kredit_bank_umum = (int)$m[1];
        }
        if (preg_match('/Kredit\s*BPR[:\s]*([0-9]+)/i', $text, $m)) {
            $summary->kredit_bpr = (int)$m[1];
        }
        if (preg_match('/Kredit\s*LP[:\s]*([0-9]+)/i', $text, $m)) {
            $summary->kredit_lp = (int)$m[1];
        }

        return $summary;
    }

    // ------------------------------------------------------------------
    // Fasilitas
    // ------------------------------------------------------------------

    private function extractFacilities(string $text): array {
        $facilities = $this->extractFacilitiesOjkLayout($text);
        if (!empty($facilities)) {
            return $facilities;
        }

        return $this->extractFacilitiesLabelColon($text);
    }

    /**
     * Each facility block in the real OJK layout ends with the label row
     * "Pelapor Cabang  Baki Debet Tanggal Update"; the actual values for
     * that row sit concatenated in the line immediately BEFORE it (e.g.
     * "008 - PT Bank Mandiri (Persero) TbkKC Jakarta - Tebet - SupomoRp
     * 0,0010 April 2019"). The bank name and branch are not reliably
     * separable from that concatenation without a bank-name dictionary,
     * so "pelapor" keeps the combined text and "cabang" is left null
     * rather than guessed. Further detail fields (kualitas, plafon,
     * kondisi, jenis) are read from labelled fields further down the
     * same block.
     */
    private function extractFacilitiesOjkLayout(string $text): array {
        $facilities = [];

        if (!preg_match_all('/Pelapor\s*Cabang\s*\t?\s*Baki\s*Debet\s*Tanggal\s*Update/u', $text, $anchors, PREG_OFFSET_CAPTURE)) {
            return $facilities;
        }

        foreach ($anchors[0] as $anchor) {
            $anchorPos = $anchor[1];
            $before = substr($text, max(0, $anchorPos - 400), min(400, $anchorPos));
            $afterStart = $anchorPos + strlen($anchor[0]);
            $after = substr($text, $afterStart, 3000);

            $f = new SlikFacilityDto();

            // Isolate the trailing "Rp <baki debet> <tanggal update>" first
            // (greedy .* picks the LAST such occurrence in the window, i.e.
            // the one immediately preceding this block's anchor). The amount
            // pattern requires exactly 2 decimal digits so it doesn't swallow
            // the day-of-month that follows with no separating space
            // (e.g. "Rp 0,0010 April 2019" is "0,00" + "10 April 2019").
            if (!preg_match('/^(.*)Rp\s*([0-9]{1,3}(?:\.[0-9]{3})*,[0-9]{2})\s*([0-9]{1,2}\s+[A-Za-z]+\s+[0-9]{4})\s*$/su', $before, $m1)) {
                // Couldn't confidently isolate this block's pelapor line; skip it
                // rather than store a partially-guessed/empty facility.
                continue;
            }

            $f->baki_debet = $this->parseIndoNumber($m1[2]);
            $f->update = $this->parseIndoDateOrMonth($m1[3]);

            // The prefix up to "Rp" may still contain earlier noise (e.g. the
            // monthly quality grid's "Kualitas 1 - Lancar" label). The real
            // "<code> - <pelapor><cabang>" text is always the LAST such
            // "digits - " occurrence in that prefix, so backtrack a greedy
            // match from the end to find it.
            if (preg_match('/.*[^0-9]([0-9]{2,8})\s*-\s*(.+)$/su', $m1[1], $m2)) {
                $f->pelapor = trim($m2[1] . ' - ' . preg_replace('/\s+/', ' ', $m2[2]));
            } else {
                $f->pelapor = trim(preg_replace('/\s+/', ' ', $m1[1]));
            }

            if (preg_match('/No\s*Rekening\s*\t?\s*Kualitas\s+([1-5]\s*-\s*[^\n]+)/u', $after, $m)) {
                $f->kualitas = trim(preg_replace('/\s+/', ' ', $m[1]));
            }

            if (preg_match('/Jenis\s*Penggunaan\s*([A-Za-z\s]+?)\s*Frekuensi\s*Restrukturisasi/u', $after, $m)) {
                $f->jenis_fasilitas = trim($m[1]);
            }

            if (preg_match('/\bPlafon\s+Rp\s*([0-9]{1,3}(?:\.[0-9]{3})*,[0-9]{2})/u', $after, $m)) {
                $f->plafon = $this->parseIndoNumber($m[1]);
            }

            if (preg_match('/Kondisi\s+(Lunas|Fasilitas Aktif)/u', $after, $m)) {
                $f->kondisi = trim($m[1]);
            }

            if (preg_match('/Tanggal\s*Awal\s*Kredit\s*([0-9]{1,2}\s+[A-Za-z]+\s+[0-9]{4})/u', $after, $m)) {
                $f->tgl_mulai = $this->parseIndoDateOrMonth($m[1]);
            }

            if (preg_match('/Tanggal\s*Jatuh\s*Tempo\s*([0-9]{1,2}\s+[A-Za-z]+\s+[0-9]{4})/u', $after, $m)) {
                $f->tgl_jatuh_tempo = $this->parseIndoDateOrMonth($m[1]);
            }

            if (preg_match('/Tanggal\s*Kondisi\s*([0-9]{1,2}\s+[A-Za-z]+\s+[0-9]{4})/u', $after, $m)) {
                $f->tgl_kondisi = $this->parseIndoDateOrMonth($m[1]);
            }

            $facilities[] = $f;
        }

        return $facilities;
    }

    private function extractFacilitiesLabelColon(string $text): array {
        $facilities = [];
        if (preg_match_all('/Pelapor[:\s]*([A-Za-z0-9\s\(\)\.,]+?)(?=\s+Plafon)\s+Plafon[:\s]*Rp?\.?\s*([0-9\.,]+)\s+Baki\s*Debet[:\s]*Rp?\.?\s*([0-9\.,]+)\s+Kualitas[:\s]*([1-5][\s\-A-Za-z]*)/i', $text, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $item) {
                $f = new SlikFacilityDto([
                    'pelapor' => trim($item[1]),
                    'plafon' => $this->parseIndoNumber($item[2]),
                    'baki_debet' => $this->parseIndoNumber($item[3]),
                    'kualitas' => trim($item[4]),
                ]);
                $facilities[] = $f;
            }
        }

        return $facilities;
    }

    private function extractDataPokok(SlikMetadataDto $meta, array $fasilitas): array {
        if (empty($meta->no_identitas) && empty($fasilitas)) {
            return [];
        }

        return [[
            'pelapor' => $fasilitas[0]->pelapor ?? null,
            'tanggal_update' => date('Y-m-d'),
            'alamat' => $meta->alamat,
            'pekerjaan' => null,
        ]];
    }

    // ------------------------------------------------------------------
    // Date / number helpers
    // ------------------------------------------------------------------

    private function parseIndoNumber(string $raw): float {
        $clean = str_replace('.', '', $raw);
        $clean = str_replace(',', '.', $clean);
        return (float)$clean;
    }

    private function parseIndoDateOrMonth(string $raw): ?string {
        $raw = trim($raw);
        if (preg_match('/^([0-9]{1,2})\s+([A-Za-z]+)\s+([0-9]{4})/u', $raw, $m)) {
            $month = self::INDO_MONTHS[strtolower($m[2])] ?? null;
            if ($month) {
                return sprintf('%04d-%02d-%02d', (int)$m[3], $month, (int)$m[1]);
            }
        }
        if (preg_match('/^([A-Za-z]+)\s+([0-9]{4})/u', $raw, $m)) {
            $month = self::INDO_MONTHS[strtolower($m[1])] ?? null;
            if ($month) {
                return sprintf('%04d-%02d-01', (int)$m[2], $month);
            }
        }
        return null;
    }

    private function parseIndoDateTime(string $raw): ?string {
        $raw = trim($raw);
        if (preg_match('/^([0-9]{1,2})\s+([A-Za-z]+)\s+([0-9]{4})\s+([0-9]{1,2}):([0-9]{2})(?::([0-9]{2}))?/u', $raw, $m)) {
            $month = self::INDO_MONTHS[strtolower($m[2])] ?? null;
            if ($month) {
                return sprintf('%04d-%02d-%02d %02d:%02d:%02d', (int)$m[3], $month, (int)$m[1], (int)$m[4], (int)$m[5], (int)($m[6] ?? 0));
            }
        }
        return $this->parseIndoDateOrMonth($raw);
    }

    private function parseIndoMonthYearToYm(string $raw): ?string {
        $raw = trim($raw);
        if (preg_match('/^([A-Za-z]+)\s+([0-9]{4})/u', $raw, $m)) {
            $month = self::INDO_MONTHS[strtolower($m[1])] ?? null;
            if ($month) {
                return sprintf('%04d-%02d', (int)$m[2], $month);
            }
        }
        return null;
    }
}
