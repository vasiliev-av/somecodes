<?php

namespace App\Http\Controllers\api\V2;

use App\Http\Controllers\Controller;
use App\Models\Chat;
use App\Models\EducationProgram;
use App\Models\Group;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function index(Request $request)
    {
        $user = auth()->user();

        $chats = Chat::whereHas('users', function($qUser) use ($user){
            $qUser->where('users.id', $user->id);
        })->with(['users' => function ($q) {
            $q->where('users.id', '<>', auth()->id());
        }])->orderBy('updated_at', 'desc')
            ->with('currentUser');

        if ($request->search){
            //если есть открытый чат - включить его в поиск
            if ($request->open_chat_id){
                $chats->where(function ($q) use ($request) {
                    $q->where('name', 'like', "%".$request->search."%");
                    $q->orWhere('id', $request->open_chat_id);
                    $q->orWhereHas('users', function ($u) use ($request) {
                        $u->where('firstname', 'like', "%".$request->search."%");
                        $u->orWhere('lastname', 'like', "%". $request->search."%");
                    });
                });
            }else{
                $chats->where('name', 'like', "%".$request->search."%");
            }
        }

        $chats->with('latestMessage');
        $chats = $chats->paginate($request->qty ?? 15);

        return response()->json($chats);
    }

    public function show(Chat $chat){
        if ($chat->isMyChat(auth()->user()->id)){
            $chat->load('latestMessage');
            $chat->load('users');

            return response()->json($chat);
        }
        return response()->json([
            'status' => 'error',
            'msg' => 'Доступ запрещен',
        ], 403, [], JSON_UNESCAPED_UNICODE);
    }

    public function store(Request $request){
        //todo перенести в модель
        if ($request->group_id & $request->program_id){
            $group = Group::find($request->group_id);
            $chat = Chat::where('group_id', $group->id)
                ->where('program_id', $request->program_id)
                ->first();

            $program = EducationProgram::find($request->program_id);
            $users = $group->users()->get()->pluck('id')->toArray();

            foreach ($program->teachers() as $teacher){
                if (!in_array($teacher->id, $users)){
                    $users[] = $teacher->id;
                }
            }
            if ($chat){
                $chat->update([
                    'name' => 'Группа '.$group->name
                ]);
                $users_id_chat = $chat->users()->get()->pluck('id')->toArray();
                //проверить всех кто уже есть, если кого то нет - добавить к чату!
                foreach ($users as $user){
                    if (!in_array($user, $users_id_chat)){
                        $chat->addUsers([
                            $user
                        ]);
                    }
                }
                //обязательно венуть модель чата - на фронте идет переход по chat.id
                //без него все поломается
                return response()->json($chat);
            }
            $data = [
                'name' => 'Группа '.$group->name,
                'user' => auth()->id(),
                'users' => $users ?? [],
                'group_id' => $group->id,
                'program_id' => $request->program_id,
            ];
        }else{
            $data = [
                'name' => $request->name,
                'user' => auth()->id(),
                'users' => $request->users ?? []
            ];
        }

        $chat = Chat::addChat($data);
        $chat->fresh();
        $chat->load(['users' => function ($q) {
            $q->where('users.id', '<>', auth()->id());
        }]);
        $chat->load('currentUser');
        $chat->load('latestMessage');
        return response()->json($chat);
    }

    public function update(Chat $chat, Request $request){
        if (!$chat->isMyChat(auth()->user()->id)){
            return response()->json([
                'status' => 'error',
                'msg' => 'Доступ запрещен',
            ], 403, [], JSON_UNESCAPED_UNICODE);
        }

        switch ($request->action){
            case 'update':
                //обновить данные чата
                if ($request->name){
                    $chat->update([
                        'name' => $request->name
                    ]);
                }
                break;
            case 'add-users':
                //добавить пользователя в чат
                $chat->addUsers($request->users ?? []);
                break;
            case 'remove-users':
                //удалить пользоваетля из чата
                $chat->removeUsers($request->users ?? []);
                break;
        }
        return response()->json($chat->load('users'));
    }
}
