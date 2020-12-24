<?php

namespace App\Models;

use App\Notifications\UserAddToChat;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Notification;

class Chat extends Model
{
    protected $table = 'chats';

    protected $fillable = [
        'name',
        'type',
        'created_at',
        'updated_at',
        'group_id',
        'program_id',
        'iom_id',
    ];

    protected $appends = [
        'program'
    ];

    public function messages(){
        return $this->hasMany(ChatMessage::class);
    }

    public function users(){
        return $this->belongsToMany(User::class, 'chat_users')
            ->whereNull('chat_users.deleted_at')
            ->withTimestamps();
    }

    public function group(){
        return $this->belongsTo(Group::class, 'group_id');
    }

    public function currentProgram(){
        return $this->belongsTo(EducationProgram::class, 'program_id');
    }

    public function currentUser()
    {
        return $this->belongsToMany(User::class, 'chat_users')
            ->where('chat_users.user_id', auth()->id())
            ->whereNull('chat_users.deleted_at')
            ->withTimestamps();
    }

    public function latestMessage()
    {
        return $this->hasOne(ChatMessage::class)->latest();
    }

    public function getProgramAttribute(){
        if ($this->currentProgram){
            return $this->currentProgram ?? null;
        }else{
            return null;
        }
    }

    public function createMessage($request){
        $this_user_id = auth()->user()->id;
        $message = ChatMessage::create([
            'type' => $request->type,
            'text' => $request->text ?? '',
            'chat_id' => $this->id,
            'user_id' => $this_user_id
        ])->fresh();

        if (isset($request->message['files'])){
            foreach ($request->message['files'] as $file)
                $message->addFile($file, 'files');
        }
        if (isset($request->message['images'])){
            foreach ($request->message['images'] as $images)
                $message->addFile($images, 'images');
        }
        if (isset($request->message['audio'])){
            foreach ($request->message['audio'] as $audio)
                $message->addAudio($audio);
        }

        if (isset($request->reply)){
            $reply_messages = ChatMessage::query()->whereIn('id', $request->reply)->get();
            foreach ($reply_messages as $reply_message){
                //$reply_message пересылаемое сообщения
                if ($reply_message->chat->isMyChat(auth()->user()->id)){
                    //если пересылаемое сообщение находится в чате доступном мне
                    //TODO если сообщение которое надо переслать имеет тип REPLY чтобы не было рекурсии
                    $message->reply_messages()->attach($reply_message->id);
                }
            }
        }

        $this->update(['updated_at' => Carbon::now()]);

        $data = null;
        $data['message'] = $message->toArray();



        //\App\Events\ChatMessage::dispatch($data);

        broadcast(new \App\Events\ChatMessage($data))->toOthers();

        foreach ($this->users as $user){
            if ($user->id != $this_user_id)
                broadcast(new \App\Events\ChatNewMessage($message->toArray(), $user));
        }



        return $message;
    }




    public function isMyChat($user_id){
        return in_array($user_id, $this->users()->pluck('chat_users.user_id')->toArray());
    }

    public function addUsers($users = []) {
        foreach ($users as $user_id){
            if (!$this->users()->find($user_id)){
                $this->users()->attach($user_id);
                $user = $this->users()->find($user_id);
                if ($user){
                    //все ок
                    Notification::send($user, new UserAddToChat($this));
                }
            }
        }
        if ($this->users()->count() > 2){
            $this->update([
                'type' => 'group'
            ]);
        }


        return true;
    }

    public function removeUsers($users = []){
        foreach ($users as $user_id){
            $this->users()->detach($user_id);
        }
        if ($this->users()->count() < 3){
            $this->update([
                'type' => 'personal'
            ]);
        }
        return true;
    }

    public static function addChat($data)
    {
        $chat = self::create([
            'name' => $data['name'],
            'group_id' => $data['group_id'] ?? null,
            'program_id' => $data['program_id'] ?? null,
            'iom_id' => $data['iom_id'] ?? null,
        ]);
        if ($chat){
            $chat->users()->attach($data['user']);

            $chat->addUsers($data['users']);

            return $chat;
        }
        return null;
    }

    public function setLastRead($message_id){
        return ChatLastReadMessage::setLastRead($this->id, $message_id);
    }
}
