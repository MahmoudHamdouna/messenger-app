<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class MessengerController extends Controller
{
    public function index($id = null)
    {
        $user = Auth::user(); //User::find(1);
        $friends = User::where('id', '<>', Auth::id())
            ->orderBy('name')
            ->paginate();

        $chats = $user->conversations()->with([
            'lastMessage',
            'participants' => function ($builder) use ($user) {
                $builder->where('id', '<>', $user->id);
            }
        ])->get();


        $messages = [];
        $activeChat = null;
        if ($id) {
            $activeChat = $chats->where('id', $id)->first();
            if ($activeChat) {
                $messages = $activeChat->messages()->with('user')->get();
            }
        }
        return view('messenger-js', [
            'friends' => $friends,
            'chats' => $chats,
            'activeChat' => $activeChat,
            'messages' => $messages
        ]);
    }
}
