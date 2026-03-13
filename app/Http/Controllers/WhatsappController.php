<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Http;
use App\Models\Whatsapp_Message;
use Illuminate\Http\Request;

class WhatsappController extends Controller
{
    public function sendWhatsappMessage(Request $request)
    {
        $phone = $request->phone;
        $message = $request->message;
        $file = $request->file('file');

        $token = "EAANdHdaGSd4BQyc0TMmZBGSymXDuYfRkZBZBvZC5KFcq5duGPuADza3YltryYkN6455BAxsO6vEpbsjRLTIZBl0BKd1ZC08CuLxLlAr2fNZCc5OJWkEZAM3g76Q2KhXy386XPaGQpTPalrqcY7H5yNI1t8WJCHHRe1dpA2I9tBQTGmE1oVSJVLW6cgTRUdyirCJx3AZDZD";
        $phoneId = "1018982427962414";

        $type = 'text';
        $mediaPath = null;

        if (!$file) {

            $response = Http::withToken($token)
                ->post("https://graph.facebook.com/v22.0/$phoneId/messages", [
                    "messaging_product" => "whatsapp",
                    "to" => $phone,
                    "type" => "text",
                    "text" => [
                        "body" => $message
                    ]
                ]);
        } else {

            $extension = strtolower($file->getClientOriginalExtension());

            $fileName = time() . '_' . $file->getClientOriginalName();

            $path = $file->storeAs('whatsapp', $fileName, 'public');

            $mediaPath = $path;

            if (in_array($extension, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                $type = 'image';
            } elseif (in_array($extension, ['mp4', 'mov', 'avi'])) {
                $type = 'video';
            } else {
                $type = 'document';
            }

            $mime = mime_content_type(storage_path('app/public/' . $path));

            $upload = Http::withToken($token)
                ->attach(
                    'file',
                    file_get_contents(storage_path('app/public/' . $path)),
                    $fileName,
                    ['Content-Type' => $mime]
                )
                ->post("https://graph.facebook.com/v22.0/$phoneId/media", [
                    'messaging_product' => 'whatsapp'
                ]);
            $uploadData = $upload->json();

            if (!$upload->successful() || !isset($uploadData['id'])) {

                return response()->json([
                    'success' => false,
                    'error' => 'Media upload failed',
                    'meta_response' => $uploadData
                ], 500);
            }

            $mediaId = $uploadData['id'];

            $response = Http::withToken($token)
                ->post("https://graph.facebook.com/v22.0/$phoneId/messages", [
                    "messaging_product" => "whatsapp",
                    "to" => $phone,
                    "type" => $type,
                    $type => [
                        "id" => $mediaId
                    ]
                ]);
        }

        $getcontact = Contact::where('phone', $phone)->first();

        $whatsapp = Whatsapp_Message::create([
            'name' => $getcontact->name,
            'phone' => $phone,
            'message' => $message,
            'media_path' => $mediaPath,
            'message_type' => $type,
            'direction' => 'sent'
        ]);

        return response()->json([
            'success' => true,
            'message' => 'WhatsApp message sent successfully',
            'data' => $whatsapp
        ]);
    }

    public function verify(Request $request)
    {
        $mode = $request->get('hub_mode');
        $token = $request->get('hub_verify_token');
        $challenge = $request->get('hub_challenge');

        if ($mode === 'subscribe' && $token === 'sales_crm_nexevo_07022026') {
            return response($challenge, 200);
        }

        return response('Verification failed', 403);
    }

    // Runs AUTOMATICALLY every time someone messages you
    public function webhook(Request $request)
    {
        Log::info('WEBHOOK HIT', $request->all());

        $data = $request->all();

        if (!isset($data['entry'][0]['changes'][0]['value']['messages'][0])) {
            return response()->json(['status' => 'ok']);
        }

        $message = $data['entry'][0]['changes'][0]['value']['messages'][0];

        $phone = $message['from'];
        $type = $message['type'];

        $text = null;
        $mediaPath = null;

        if ($type == 'text') {
            $text = $message['text']['body'];
        } elseif ($type == 'image') {
            $mediaId = $message['image']['id'];
            $mediaPath = $this->downloadMedia($mediaId);
        } elseif ($type == 'video') {
            $mediaId = $message['video']['id'];
            $mediaPath = $this->downloadMedia($mediaId);
        } elseif ($type == 'document') {
            $mediaId = $message['document']['id'];
            $mediaPath = $this->downloadMedia($mediaId);
        } elseif ($type == 'audio') {
            $mediaId = $message['audio']['id'];
            $mediaPath = $this->downloadMedia($mediaId);
        }

        $getcontact = Contact::where('phone', $phone)->first();

        Whatsapp_Message::create([
            'name' => $getcontact->name,
            'phone' => $phone,
            'message' => $text,
            'media_path' => $mediaPath,
            'message_type' => $type,
            'direction' => 'received'
        ]);

        return response()->json(['status' => 'received']);
    }

    private function downloadMedia($mediaId)
    {
        $token = "EAANdHdaGSd4BQ6MONZAqP9ZAgYuhsU1V2eSAOGM1zaVm8HgbYXHF5FZBd0di9VbuZB8XRRYWT5fqR1ocuZAJ8SISNB9c3sqGtO4QzkrtgC510VHVIwe6mvqbN3PhlxN4Pncwg7z40BUMF0SKmQpcJG19twL42bb5LGSactztAjSoRvFDtix1dMdtjFEzOUQZDZD";

        $meta = Http::withToken($token)
            ->get("https://graph.facebook.com/v22.0/$mediaId");

        if (!$meta->successful()) {
            Log::error('MEDIA META ERROR', $meta->json());
            return null;
        }

        $url = $meta['url'];

        $file = Http::withToken($token)->get($url);

        if (!$file->successful()) {
            Log::error('MEDIA DOWNLOAD FAILED');
            return null;
        }

        $name = time() . '_' . $mediaId;

        $path = storage_path('app/public/whatsapp/' . $name);

        if (!file_exists(dirname($path))) {
            mkdir(dirname($path), 0777, true);
        }

        file_put_contents($path, $file->body());

        return 'whatsapp/' . $name;
    }

    public function listmessages(Request $request)
    {
        $phone = $request->phone;

        $messages = Whatsapp_Message::where('phone', $phone)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json($messages);
    }
}
