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

            // Transkripsi audio panjang menggunakan metode asinkron
            $operationName = $this->transcribeAudio($gcsUri);

            // Periksa status transkripsi secara berkala
            $transcript = '';
            $isDone = false;
            while (!$isDone) {
                $operationStatus = $this->checkTranscriptionStatus($operationName);
                if (isset($operationStatus['done']) && $operationStatus['done']) {
                    $isDone = true;
                    $transcript = $operationStatus['response']['results'][0]['alternatives'][0]['transcript'];
                } else {
                    sleep(5); // Tunggu 5 detik sebelum memeriksa lagi
                }
            }

            // Summarize hasil transkripsi menggunakan Cohere
            $summary = $this->summarizeTranscript($transcript);

            // Kembalikan respons JSON
            return response()->json([
                'audioUrl' => "https://storage.googleapis.com/" . env('GCS_BUCKET') . "/audio-files/" . basename($fullMp3Path), // URL publik untuk memutar audio
                'gcsUri' => $gcsUri, // URI GCS untuk transkripsi
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

    private function transcribeAudio($gcsUri)
    {
        // Pastikan URI GCS dalam format `gs://`
        if (!str_starts_with($gcsUri, 'gs://')) {
            throw new \Exception('URI GCS harus dalam format `gs://`.');
        }

        $curl = curl_init();

        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://speech.googleapis.com/v1/speech:longrunningrecognize?key=' . env('GCP_API_KEY'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => json_encode([
                'config' => [
                    'encoding' => 'MP3',
                    'sampleRateHertz' => 16000,
                    'languageCode' => 'id-ID',
                    'enableAutomaticPunctuation' => true,
                    'model' => 'latest_long', // Tetap gunakan model default
                ],
                'audio' => [
                    'uri' => $gcsUri, // Gunakan URI GCS (`gs://`)
                ],
            ]),
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
            ),
        ));

        $response = curl_exec($curl);
        curl_close($curl);

        $responseData = json_decode($response, true);

        if (isset($responseData['error'])) {
            throw new \Exception('Gagal memulai operasi. Respons API: ' . print_r($responseData, true));
        }

        // Kembalikan nama operasi untuk memeriksa status transkripsi
        return $responseData['name'];
    }

    private function checkTranscriptionStatus($operationName)
    {
        $curl = curl_init();
    
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://speech.googleapis.com/v1/operations/' . $operationName . '?key=' . env('GCP_API_KEY'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'GET',
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json',
            ),
        ));
    
        $response = curl_exec($curl);
        curl_close($curl);
    
        $responseData = json_decode($response, true);
    
        if (isset($responseData['error'])) {
            throw new \Exception('Gagal memeriksa status operasi: ' . print_r($responseData, true));
        }
    
        return $responseData;
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