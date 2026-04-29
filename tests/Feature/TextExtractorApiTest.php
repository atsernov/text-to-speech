<?php

use Illuminate\Http\UploadedFile;

// Helpers: create real UploadedFile instances the controller can actually read

/**
 * Wraps a string in a real temp file and returns it as an UploadedFile.
 * Using a real file (not fake content) ensures the service can read it.
 */
function txtUpload(string $content, string $name = 'test.txt'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'apitest_');
    file_put_contents($path, $content);

    return new UploadedFile($path, $name, 'text/plain', null, true);
}

/**
 * Builds a minimal valid .docx (ZIP + word/document.xml) and returns it
 * as an UploadedFile. Mirrors the makeDocx() helper from TextExtractorTest.
 */
function docxUpload(array $paragraphs, string $name = 'test.docx'): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'apitest_').'.docx';
    $wNs = 'http://schemas.openxmlformats.org/wordprocessingml/2006/main';

    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<w:document xmlns:w="'.$wNs.'"><w:body>';
    foreach ($paragraphs as $para) {
        $xml .= '<w:p><w:r><w:t>'.htmlspecialchars($para, ENT_XML1, 'UTF-8').'</w:t></w:r></w:p>';
    }
    $xml .= '</w:body></w:document>';

    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('word/document.xml', $xml);
    $zip->close();

    return new UploadedFile(
        $path,
        $name,
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        null,
        true,
    );
}

// POST /api/extract-text - txt files

test('extracts text from an uploaded txt file and returns json', function () {
    $file = txtUpload("Tere maailm!\nTeine rida.");

    $this->post('/api/extract-text', ['file' => $file])
        ->assertOk()
        ->assertJsonPath('text', "Tere maailm!\nTeine rida.")
        ->assertJsonPath('characters', mb_strlen("Tere maailm!\nTeine rida."));
});

test('txt response character count matches text length', function () {
    $content = 'Lühike tekst.';
    $file = txtUpload($content);

    $response = $this->post('/api/extract-text', ['file' => $file])
        ->assertOk();

    // characters field must equal mb_strlen of the returned text
    expect($response->json('characters'))->toBe(mb_strlen($response->json('text')));
});

// POST /api/extract-text - docx files

test('extracts text from an uploaded docx file and returns json', function () {
    $file = docxUpload(['Esimene lõik.', 'Teine lõik.']);

    $response = $this->post('/api/extract-text', ['file' => $file])
        ->assertOk()
        ->assertJsonStructure(['text', 'characters']);

    $text = $response->json('text');
    expect($text)->toContain('Esimene lõik.')
        ->and($text)->toContain('Teine lõik.');
});

test('docx response character count matches text length', function () {
    $file = docxUpload(['Tekst lõigus.']);

    $response = $this->post('/api/extract-text', ['file' => $file])
        ->assertOk();

    expect($response->json('characters'))->toBe(mb_strlen($response->json('text')));
});

// POST /api/extract-text - validation errors (wrong input)

test('returns 422 validation error when no file is provided', function () {
    $this->postJson('/api/extract-text', [])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

test('returns 422 validation error when an unsupported file type is uploaded', function () {
    $file = UploadedFile::fake()->create('photo.jpg', 10, 'image/jpeg');

    $this->withHeaders(['Accept' => 'application/json'])
        ->post('/api/extract-text', ['file' => $file])
        ->assertStatus(422)
        ->assertJsonValidationErrors(['file']);
});

// POST /api/extract-text - service-level errors - 422 with "error" key

test('returns 422 with error message when docx content is malformed', function () {
    // A file with a .docx name but invalid ZIP content
    $path = tempnam(sys_get_temp_dir(), 'apitest_');
    file_put_contents($path, 'this is not a zip');

    $file = new UploadedFile(
        $path,
        'broken.docx',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        null,
        true,
    );

    $this->post('/api/extract-text', ['file' => $file])
        ->assertStatus(422)
        ->assertJsonStructure(['error']);
});
