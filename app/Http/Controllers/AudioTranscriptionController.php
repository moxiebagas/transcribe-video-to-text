<?php

namespace App\Http\Controllers;

use FFMpeg\FFMpeg;
use FFMpeg\Format\Audio\Wav;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Support\Facades\Log;

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

            // Path untuk menyimpan file WAV
            $outputWavPath = 'audios/' . pathinfo($videoFile->hashName(), PATHINFO_FILENAME) . '.wav';
            $fullWavPath = Storage::disk('public')->path($outputWavPath);

            // Konversi video ke WAV
            if (!$this->convertVideoToWav($fullVideoPath, $fullWavPath)) {
                throw new \Exception('Gagal mengonversi video ke WAV.');
            }

            // Hapus file video asli (opsional)
            Storage::disk('public')->delete($videoPath);

            // Encode file WAV ke base64
            $base64Audio = $this->encodeAudioToBase64($fullWavPath);

            // Transkripsi audio menggunakan Google Speech-to-Text API dengan cURL
            $transcript = $this->transcribeAudio($base64Audio);

            // Summarize hasil transkripsi menggunakan Cohere
            $summary = $this->summarizeTranscript($transcript);

            // Kembalikan respons JSON
            return response()->json([
                'audioUrl' => Storage::url($outputWavPath),
                'transcript' => $transcript,
                'summary' => $summary,
            ]);
        } catch (\Exception $e) {
            return response()->json(['error' => 'Gagal memproses: ' . $e->getMessage()], 500);
        }
    }

    private function encodeAudioToBase64($filePath)
    {
        $audioContent = file_get_contents($filePath);
        return base64_encode($audioContent);
    }

    private function convertVideoToWav($videoPath, $outputPath)
    {
        try {
            $ffmpeg = FFMpeg::create([
                'ffmpeg.binaries'  => 'C:/ffmpeg/bin/ffmpeg.exe', // Sesuaikan dengan path FFmpeg
                'ffprobe.binaries' => 'C:/ffmpeg/bin/ffprobe.exe',
                'timeout'          => 3600, // Timeout 1 jam
            ]);

            $video = $ffmpeg->open($videoPath);

            // Konfigurasi format WAV (LINEAR16)
            $format = new Wav();
            $format->setAudioChannels(1) // Ubah ke mono
                   ->setAudioKiloBitrate(128); // Set kualitas audio

            // Simpan file dalam format WAV
            $video->save($format, $outputPath);

            return true;
        } catch (\Exception $e) {
            Log::error('Gagal mengonversi video ke WAV: ' . $e->getMessage());
            return false;
        }
    }

    private function transcribeAudio($base64Audio)
    {
        // API key Anda
        $apiKey = env('GCP_API_KEY'); // Ganti dengan API key Anda

        // Data yang akan dikirim ke API
        $data = [
            'config' => [
                'encoding' => 'LINEAR16', // Format audio (WAV)
                'languageCode' => 'id-ID', // Bahasa
            ],
            'audio' => [
                'content' => $base64Audio, // Audio dalam bentuk base64
            ],
        ];

        // Inisialisasi cURL
        $ch = curl_init();

        // Set URL dan opsi cURL
        curl_setopt($ch, CURLOPT_URL, "https://speech.googleapis.com/v1/speech:recognize?key=$apiKey");
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

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

        // Periksa apakah ada hasil transkripsi
        if (isset($responseData['results'][0]['alternatives'][0]['transcript'])) {
            return $responseData['results'][0]['alternatives'][0]['transcript'];
        } else {
            throw new \Exception('Tidak ada hasil transkripsi. Respons API: ' . print_r($responseData, true));
        }
    }

    private function summarizeTranscript($transcript)
    {
        // Ambil API key dari .env
        $apiKey = env('COHERE_API_KEY');

        // Inisialisasi Guzzle client
        $client = new Client();

        try {
            $transcriptWithInstruction = "Ringkas atau rangkum teks berikut dalam bahasa Indonesia: " . $transcript;
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