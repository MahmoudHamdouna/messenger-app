<?php

namespace App\Http\Controllers;

use App\Events\MessageCreated;
use App\Models\Conversation;
use App\Models\Recipient;
use App\Models\User;
use GuzzleHttp\Promise\Create;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Throwable;

class MessagesController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index($id)
    {
        $user = Auth::user();
        $conversation = $user->conversations()
            ->with([
                'participants' => function ($builder) use ($user) {
                    $builder->where('id', '<>', $user->id);
                }
            ])
            ->findOrFail($id);

        return [
            'conversation' => $conversation,
            'messages' => $conversation->messages()->with('user')->paginate(),
        ];
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'message' => ['required', 'string'],
            'conversation_id' => [
                Rule::requiredIf(function () use ($request) {
                    return !$request->input('user_id');
                }),
                'integer',
                'exists:conversations,id'
            ],
            'user_id' => [
                Rule::requiredIf(function () use ($request) {
                    return !$request->input('conversation_id');
                }),
                'integer',
                'exists:users,id'
            ],
        ]);

        $user = Auth::user(); //User::find(1);
        $conversation_id = $request->post('conversation_id');
        $user_id = $request->post('user_id');


        DB::beginTransaction();
        try {
            if ($conversation_id) {
                $conversation = $user->conversations()->findOrFail($conversation_id);
            } else {

                $conversation = Conversation::where('type', '=', 'peer')
                    ->whereHas('participants', function ($builder) use ($user, $user_id) {
                        $builder->join('participants as participants2', 'participants2.conversation_id', '=', 'participants.conversation_id')
                            ->where('participants.user_id', '=', $user_id)
                            ->where('participants2.user_id', '=', $user->id);
                    })->first();

                if (!$conversation) {
                    $conversation = Conversation::create([
                        'user_id' => $user->id,
                        'type' => 'peer'
                    ]);

                    $conversation->participants()->attach([
                        $user->id,
                        $user_id
                    ]);
                }
            }
            $message = $conversation->messages()->Create([
                'user_id' => $user->id,
                'body' => $request->post('message')
            ]);

            DB::statement('
                INSERT INTO recipients (user_id, message_id)
                SELECT user_id, ? FROM participants
                WHERE conversation_id = ?
            ', [$message->id, $conversation->id]);

            $conversation->update([
                'last_message_id' => $message->id
            ]);

            DB::commit();

            $message->load('user');

            broadcast(new MessageCreated($message));
        } catch (Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        return $message;
    }

    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        Recipient::where([
            'user_id' => Auth::id(),
            'message_id' => $id,
        ])->delete();

        return [
            'message' => 'deleted'
        ];
    }
}
