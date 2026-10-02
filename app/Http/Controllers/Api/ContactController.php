<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ContactResource;
use App\Models\Contact;
use App\Support\Js;
use Illuminate\Http\Request;

class ContactController extends Controller
{
    /**
     * POST /api/contact - store a contact form submission.
     */
    public function store(Request $request)
    {
        try {
            $body = $this->body($request, 'name');
            $values = [
                'name' => $this->field($body, 'name'),
                'email' => $this->field($body, 'email'),
                'subject' => $this->field($body, 'subject'),
                'message' => $this->field($body, 'message'),
            ];

            foreach ($values as $value) {
                if (! Js::truthy($value)) {
                    return $this->respond([
                        'success' => false,
                        'message' => 'All fields are required',
                        'data' => null,
                    ], 400);
                }
            }

            $contact = (new Contact)->fillFromApi(Contact::schema()->forCreate($values));
            $contact->save();

            return $this->respond([
                'success' => true,
                'message' => 'Contact message submitted successfully',
                'data' => (new ContactResource($contact))->resolve(),
            ], 201);
        } catch (\Throwable $e) {
            return $this->respond([
                'success' => false,
                'message' => 'Failed to submit contact message',
                'error' => $this->errorMessage($e),
                'data' => null,
            ], 500);
        }
    }
}
