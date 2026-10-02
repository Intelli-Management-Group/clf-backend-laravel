<?php

namespace App\Http\Controllers;

class WelcomeController extends Controller
{
    /**
     * GET / - API welcome message.
     */
    public function __invoke()
    {
        return $this->respond(['message' => 'Welcome to CLF Backend API']);
    }
}
