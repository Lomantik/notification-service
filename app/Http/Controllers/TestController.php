<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class TestController extends Controller
{
    public function index(): void
    {
        echo 'hello!:)';

        //        $users = User::with(['notifications'])->get();
        //        dd($users->toArray());

        //        $user = User::findOrFail(1);

        //        $result = $user->load('notificationDeliveries');

        //        $result = Cache::remember(
        //            "test_user_notifications_{$user->id}",
        //            1,
        //            fn () => $user->load('notificationDeliveries')->toArray()
        //        );

        //        dd($result);
    }
}
