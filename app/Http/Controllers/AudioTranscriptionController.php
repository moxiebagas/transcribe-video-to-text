<?php

namespace App\Http\Controllers;

use FFMpeg\FFMpeg;
use FFMpeg\Format\Audio\Mp3;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;
use Google\Cloud\Storage\StorageClient;

class AudioTranscriptionController extends Controller
{
    public function processVideoAndTranscribe(Request $request)
    {
        // Validasi file video
        $request->validate([
            'video' => 'required|mimetypes:video/mp4,video/x-matroska|max:50000', // Hanya terima MP4 dan MKV
        ]);

        try {
            // Simpan file video
            $videoFile = $request->file('video');
            $videoPath = $videoFile->store('videos', 'public');
            $fullVideoPath = Storage::disk('public')->path($videoPath);

            // Path untuk menyimpan file MP3
            $outputMp3Path = 'audios/' . pathinfo($videoFile->hashName(), PATHINFO_FILENAME) . '.mp3';
            $fullMp3Path = Storage::disk('public')->path($outputMp3Path);

            // Konversi video ke MP3
            if (!$this->convertVideoToMp3($fullVideoPath, $fullMp3Path)) {
                throw new \Exception('Gagal mengonversi video ke MP3.');
            }

            // Hapus file video asli (opsional)
            Storage::disk('public')->delete($videoPath);

            // Unggah file MP3 ke Google Cloud Storage (GCS)
            $gcsUri = $this->uploadFileToGCS($fullMp3Path, 'audio-files/' . basename($fullMp3Path));

            // Transkripsi audio menggunakan Google Speech-to-Text API dengan URI GCS
            $transcript = $this->transcribeAudio($gcsUri);

            // Summarize hasil transkripsi menggunakan Cohere
            $summary = $this->summarizeTranscript($transcript);

            // Kembalikan respons JSON
            return response()->json([
                'audioUrl' => 'https://storage.googleapis.com/' . env('GCS_BUCKET') . '/audio-files/' . basename($fullMp3Path),
                'transcript' => $transcript,
                'summary' => $summary,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Gagal memproses: ' . $e->getMessage()], 500);
        }
    }

    private function convertVideoToMp3($videoPath, $outputPath)
    {
        try {
            $ffmpeg = FFMpeg::create([
                'ffmpeg.binaries'  => 'C:/ffmpeg/bin/ffmpeg.exe', // Sesuaikan dengan path FFmpeg
                'ffprobe.binaries' => 'C:/ffmpeg/bin/ffprobe.exe',
                'timeout'          => 3600, // Timeout 1 jam
            ]);

            $video = $ffmpeg->open($videoPath);

            // Konfigurasi format MP3
            $format = new Mp3();
            $format->setAudioChannels(1) // Ubah ke mono
                   ->setAudioKiloBitrate(64); // Set kualitas audio

            // Simpan file dalam format MP3
            $video->save($format, $outputPath);

            return true;
        } catch (\Exception $e) {
            Log::error('Gagal mengonversi video ke MP3: ' . $e->getMessage());
            return false;
        }
    }

    private function uploadFileToGCS($filePath, $objectName)
    {
        $keyFilePath = base_path('bucket.json');

        if (!file_exists($keyFilePath)) {
            throw new \Exception('File kredensial tidak ditemukans: ' . $keyFilePath);
        }
        // Inisialisasi Google Cloud Storage client
        $storage = new StorageClient([
            'projectId' => env('GOOGLE_CLOUD_PROJECT'),
            'keyFilePath' => $keyFilePath,
        ]);
        // Unggah file ke GCS
        $bucket = $storage->bucket(env('GCS_BUCKET'));
        $bucket->upload(fopen($filePath, 'r'), [
            'name' => $objectName,
        ]);

        // Kembalikan URI GCS
        return "gs://" . env('GCS_BUCKET') . "/$objectName";
    }

    private function transcribeAudio($audioUri)
    {
        set_time_limit(3600); // Set timeout menjadi 1 jam

        // API key Anda
        $apiKey = env('GCP_API_KEY'); // Ganti dengan API key Anda

        // Data yang akan dikirim ke API
        $data = [
            'config' => [
                'encoding' => 'MP3', // Format audio (MP3)
                'sampleRateHertz' => 16000,
                'languageCode' => 'id-ID', // Bahasa
                'enableAutomaticPunctuation' => true,
                'model' => 'latest_long'
            ],
            'audio' => [
                'uri' => $audioUri, // Gunakan URI GCS
            ],
        ];

        // Inisialisasi cURL
        $ch = curl_init();

        // Set URL dan opsi cURL
        curl_setopt($ch, CURLOPT_URL, "https://speech.googleapis.com/v1/speech:longrunningrecognize?key=$apiKey");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3600); // Set timeout cURL

        // Eksekusi cURL dan dapatkan respons
        $response = curl_exec($ch);

        // Periksa error cURL
        if (curl_errno($ch)) {
            throw new \Exception('Error cURL: ' . curl_error($ch));
        }

        // Tutup cURL
        curl_close($ch);

        // Decode respons JSON
        $responseData = json_decode($response, true);

        // Periksa apakah operasi berhasil dimulai
        if (isset($responseData['name'])) {
            return $this->checkOperationStatus($responseData['name']); // Periksa status operasi
        } else {
            throw new \Exception('Gagal memulai operasi. Respons API: ' . print_r($responseData, true));
        }
    }

    private function checkOperationStatus($operationName)
    {
        set_time_limit(3600); // Set timeout menjadi 1 jam

        // API key Anda
        $apiKey = env('GCP_API_KEY'); // Ganti dengan API key Anda

        // Inisialisasi cURL
        $ch = curl_init();

        // Set URL dan opsi cURL
        curl_setopt($ch, CURLOPT_URL, "https://speech.googleapis.com/v1/operations/$operationName?key=$apiKey");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3600); // Set timeout cURL

        // Eksekusi cURL dan dapatkan respons
        $response = curl_exec($ch);

        // Periksa error cURL
        if (curl_errno($ch)) {
            throw new \Exception('Error cURL: ' . curl_error($ch));
        }

        // Tutup cURL
        curl_close($ch);

        // Decode respons JSON
        $responseData = json_decode($response, true);

        // Periksa apakah operasi selesai
        if (isset($responseData['done']) && $responseData['done']) {
            if (isset($responseData['response']['results'][0]['alternatives'][0]['transcript'])) {
                return $responseData['response']['results'][0]['alternatives'][0]['transcript'];
            } else {
                throw new \Exception('Tidak ada hasil transkripsi. Respons API: ' . print_r($responseData, true));
            }
        } else {
            // Jika operasi belum selesai, tunggu dan periksa lagi
            sleep(10); // Tunggu 10 detik sebelum memeriksa lagi
            return $this->checkOperationStatus($operationName);
        }
    }

    private function summarizeTranscript($transcript)
    {
        // Ambil API key dari .env
        $apiKey = env('COHERE_API_KEY');

        // Inisialisasi Guzzle client
        $client = new Client();

        try {
            $transcriptWithInstruction = "Summarize the following text **strictly in Indonesian**. The summary must be fully in Indonesian, using natural and fluent language as if explaining to a native speaker. Do not include any introductory labels or language indicators. The summary should be concise, clear, and easy to understand, while keeping the key points intact:\n\n" . $transcript;
            
            // Kirim permintaan ke Cohere API
            $response = $client->post('https://api.cohere.ai/v1/summarize', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                ],
                'json' => [
                    'text' => $transcriptWithInstruction, // Teks yang akan diringkas
                    'length' => 'short', // Panjang ringkasan (short, medium, long)
                    'format' => 'paragraph', // Format ringkasan (paragraph, bullets)
                    'extractiveness' => 'low',
                    'model' => 'summarize-xlarge', // Model yang digunakan
                ],
            ]);

            // Decode respons JSON
            $responseData = json_decode($response->getBody(), true);

            // Ambil ringkasan dari respons Cohere
            $summary = $responseData['summary'];
            return trim($summary); // Hilangkan spasi di awal dan akhir
        } catch (GuzzleException $e) {
            throw new \Exception('Gagal melakukan summarization dengan Cohere: ' . $e->getMessage());
        }
    }

    public function showUploadForm()
    {
        return view('upload');
    }
}