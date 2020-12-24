<?php

namespace App\Http\Requests\Api\V1\QuizController;

use Illuminate\Foundation\Http\FormRequest;
use App\Models\QuizCommonQuestion;
use App\Models\BankQuestion;
use App\Models\ModelHasUser;
use App\Models\RoleOnModel;
use App\Models\Quiz;

class Update extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    public function all($keys = [])
    {
        $data = parent::all($keys);
        $data['quizzes'] = request('quiz.id');
        return $data;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $rules = [];

        switch ($this->action)
        {
            /* обновление базовых данных теста */
            case 'data':
                return [
                    'name' => 'required|string|between:1,255',
                    'lead_time' => 'required|integer|between:1,99999999',
                    //'start_time_availability' => 'nullable|date_format:Y-m-d H:i:s|after:'.now(),
                    'start_time_availability' => 'nullable|date_format:Y-m-d H:i:s',
                    'end_time_availability' => 'required|after:start_time_availability|date_format:Y-m-d H:i:s',
                    'passing_score' => 'required|integer|between:1,999999999',
                    'change_answers' => 'required|boolean',
                    'count_attempts' => 'required|integer|between:1,10000',
                    'see_result_questions' => 'required|boolean',
                    'protected' => 'required|boolean',
                    'password' => 'required_if:protected,1|string|min:1,255',
                ];

            /* обновление параметров теста */
            case 'params':
                return [
                    'change_answers' => 'required|boolean',
                    'count_attempts' => 'required|integer|between:1,10000',
                    'see_result_questions' => 'required|boolean',
                    'protected_enabled' => 'required|boolean',
                    'password_protected' => 'required|string|min:1,20',
                ];

            /* добавление конрктеного вопроса в банк */
            case 'add-question-specific':
                /* проверка, что этот вопрос в тесте не задействован */
                /* где модель - вопрос с конкретным id */
                $questionRules = request('quiz')->questions()
                    ->where(function($q) {
                        $q->whereHasMorph('model', QuizCommonQuestion::class, function ($question) {
                            $question->where('id', $this->question_id);
                        })
                            /* или банк, который содержит в себе этот вопрос */
                            ->orWhereHasMorph('model', BankQuestion::class, function ($bank) {
                                $bank->whereHas('quizCommonQuestions', function ($question) {
                                    $question->where('id', $this->question_id);
                                });
                            });

                    })
                    ->first();
				//dd($questionRules);
                /* если есть правило - запретить для указанного вопроса */
                if ($questionRules)
                {
                    return [
                        'question_id' => 'required|integer|not_in:'.$this->question_id,
                    ];
                }

                return [
                    'question_id' => [//'required|integer|exists:quiz_common_questions,id,deleted_at,NULL',
                        'required',
                        'integer',
                        'exists:quiz_common_questions,id,deleted_at,NULL',
                        function ($attribute, $value, $fail) {
                            /* если вопрос без ответа помещается в тест */
                            $question = QuizCommonQuestion::find($this->question_id);
                            if (!$question || request('quiz.type') == 'test' && $question->type == 'interview')
                            {
                                $fail('Вопрос не найден, либо вопрос с типом interview помещается в тест с типом test.');
                            }
                        }
                    ],
                    'point_count' => 'required|integer|min:1',
                ];

            /* добавление рандомных вопросов из банка */
            case 'add-question-random':
                // нужно проверить, что банк не задействован
                // нужно проверить, что вопросы из этого банка не задействованы

                $questionRules = request('quiz')->questions()
                    ->where(function ($q) {
                        /* где модель - указанный банк */
                        $q->whereHasMorph('model', BankQuestion::class, function ($bank) {
                            $bank->where('id', $this->bank_id);
                        })

                            /* где модель - вопросы из указанного банка */
                            ->orWhereHasMorph('model', QuizCommonQuestion::class, function ($question) {
                                $question->whereHas('bankQuestion', function ($bank) {
                                    $bank->where('id', $this->bank_id);
                                });
                            });
                    })
                    ->first();

                /* если есть правило - запретить для указанного банка */
                if ($questionRules)
                {
                    return [
                        'bank_id' => 'required|integer|not_in:'.$this->bank_id,
                    ];
                }

                $questionBankCount = QuizCommonQuestion::where('bank_question_id', $this->bank_id)->count();
                return [
                    'bank_id' => [
                        'required',
                        'integer',
                        'exists:bank_questions,id,deleted_at,NULL',
                        function ($attribute, $value, $fail)
                        {
                            /* если тип банка interview а у теста test - выдать ошибку */
                            $bank = BankQuestion::find($this->bank_id);
                            if (!$bank || $bank->type == 'interview' && request('quiz.type') == 'test')
                            {
                                $fail('Банк не найден, либо у банка тип interview, а у теста - test');
                            }
                        }
                    ],
                    'question_count' => [
                        'required',
                        'integer',
                        function ($attribute, $value, $fail) use ($questionBankCount)
                        {
                            if ($this->question_count > $questionBankCount)
                                $fail('Количество вопросов в банке: '.$questionBankCount);
                        }
                    ],
                    'point_count' => 'required|integer|min:1',
                ];

            /* добавление всех вопросов из определенного банка */
            case 'add-question-all':
                $questionRules = request('quiz')->questions()
                    /* указанный банк */
                    ->whereHasMorph('model', BankQuestion::class, function ($bank) {
                        $bank->where('id', $this->bank_id);
                    })

                    /* вопросы, которые есть в указанном банке */
                    ->orWhereHasMorph('model', QuizCommonQuestion::class, function ($question) {
                        $question->whereHas('bankQuestion', function ($bank) {
                            $bank->where('id', $this->bank_id);
                        });
                    })

                    ->first();

                /* если есть правило - запретить для указанного банка */
                if ($questionRules)
                {
                    return [
                        'bank_id' => 'required|integer|not_in:'.$this->bank_id,
                    ];
                }

                return [
                    'bank_id' => [
                        'required',
                        'integer',
                        'exists:bank_questions,id,deleted_at,NULL',
                        function ($attribute, $value, $fail)
                        {
                            /* если тип банка interview а у теста test - выдать ошибку */
                            $bank = BankQuestion::find($this->bank_id);
                            if (!$bank || $bank->type == 'interview' && request('quiz.type') == 'test')
                            {
                                $fail('Банк не найден, либо у банка тип interview, а у теста - test');
                            }
                        }
                    ],
                    'point_count' => 'required|integer|min:1',
                ];

            /* удаление вопроса или правила выборки вопроса из теста */
            case 'remove-question-rule':
                return [
//                    'quiz_question_id' => 'required|integer|exists:quiz_questions,id,quiz_id,'.request('quiz.id'),
                    'quiz_question_id' => 'required|integer',
                ];

            case 'update-question-rule':
                return [
                    'quiz_question_id' => 'required|integer',
                    'point_count' => 'required|integer|min:1',
                ];
                break;

            /* удалить все вопросы из теста */
            case 'remove-all-questions':
                return [];

            /* создание попытки прохождения теста */
            case 'create-attempt':
                return [
                    'quizzes' => [
                        /* если правил формирования нет - запретить создание попытки */
                        function ($attribute, $value, $fail)
                        {
                            if (!request('quiz.questions')->count())
                            {
                                $fail('В тесте нет правил формирования.');
                            }
                        },

                        /* если лимит попыток исчерпан */
                        function ($attribute, $value, $fail) {
                            $countAttempts = request('quiz')->attempts()->where('user_id', auth()->id())->count();
                            if (request('quiz.params')['count_attempts'] <= $countAttempts)
                            {
                                $fail('Лимит попыток исчерпан.');
                            }
                        },

                        /* если есть хоть одна активная попытка */

                        function ($attribute, $value, $fail) {
                            $countActiveAttempts =  request('quiz')
                                ->attempts()
                                ->where('user_id', auth()->id())
                                ->where('finished_at', '>', now())
                                ->where('start_time', '>', now()->sub('minutes', request('quiz.lead_time')))
                                ->count();
                            if ($countActiveAttempts)
                            {
                                $fail('Имеется незавершенная попытка.');
                            }
                        },


                        /* если тест по времени не дотупен */
                        function ($attribute, $value, $fail) {
                            if (
                                request('quiz')->start_time_availability > now() ||
                                request('quiz')->end_time_availability < now()
                            ) {
                                $fail('Тест не доступен по времени.');
                            }
                        },

                        /* если требуется пароль, проверить  */
                        function ($attribute, $value, $fail) {
                            if (request('quiz.protected') == true && request('quiz.password') != $this->password) {
                                $fail('Пароль активации теста не передан или не совпадает.');
                            }
                        },

                        /* если тест-опрос, нужно проверить правило */
                        function ($attribute, $value, $fail) {
                            $quiz = request('quiz'); // тест

                            if ($quiz->type == 'interview')
                            {

                                switch ($quiz->available_rule)
                                {
                                    /* все пользователи */
                                    case 'all':
                                        // тут ничего делать не нужно
                                        break;

                                    /* пользователи организации к которой прикреплен опрос */
                                    case 'users-in-org':
                                        $model = ModelHasUser::whereHasMorph(
                                            'model',
                                            get_class(request('quiz.organization')),
                                            function ($organization)
                                            {
                                                $organization->where('id', request('quiz.organization.id'));
                                            }
                                        )
                                        ->where('user_id', auth()->id() ?? 0)
                                        ->first();
                                        if (!$model) {
                                            $fail('Пользователь не состоит в этой организации.');
                                        }
                                        break;

                                    /* пользователи с определенной ролью в организации, к которой прикреплен опрос */
                                    case 'users-with-role':
                                        $model = RoleOnModel::whereHasMorph(
                                            'model',
                                            get_class(request('quiz.organization')),
                                            function ($organization) {
                                                $organization->where('id', request('quiz.organization.id'));
                                            }
                                        )
                                        ->where('user_id', auth()->id() ?? 0)
                                        ->where('role_id', request('quiz')->available_role_id ?? 0)
                                        ->first();
                                        if (!$model) {
                                            $fail('У пользовтаеля недостаочно прав.');
                                        }
                                        break;
                                }

                            }

                        }
                    ],
                ];

            /* принудительное создание попытки преподом для определенного студента */
            case 'add-forcibly-attempt':
                return [
                    'quizzes' => [
                        /* тип теста - test */
                        'exists:quizzes,id,deleted_at,NULL,type,test',

                        /* если правил формирования нет - запретить создание попытки */
                        function ($attribute, $value, $fail)
                        {
                            if (!request('quiz.questions')->count())
                            {
                                $fail('В тесте нет правил формирования.');
                            }
                        },

                        /* если есть хоть одна активная попытка */
                        function ($attribute, $value, $fail) {
                            $countActiveAttempts =  request('quiz')
                                ->attempts()
                                ->where('finished_at', '>', now())
                                ->where('start_time', '>', now()->sub('minutes', request('quiz.lead_time')))
                                ->count();
                            if ($countActiveAttempts)
                            {
                                $fail('Имеется незавершенная попытка.');
                            }
                        },

                        /* если тест по времени не дотупен */
                        function ($attribute, $value, $fail) {
                            if (
                                request('quiz')->start_time_availability > now() ||
                                request('quiz')->end_time_availability < now()
                            ) {
                                $fail('Тест не доступен по времени.');
                            }
                        }
                    ],
                    'user_id' => 'required|integer|exists:users,id,deleted_at,NULL',
                ];

            /* дать дополнительную попытку пользователю минуя всех правил для списка логинов */
            case 'add-forcibly-attempt-by-logins':
                return [
                    'logins' => 'required|array',
                    'logins.*' => 'required|string',
                ];

            /* удаление всех попыток пользватлей по текущему тесту */
            case 'del-all-users-attempts':
                return [
                    'logins' => 'required|array',
                    'logins.*' => 'required'
                ];

            /* создание кастомной шкалы */
            case 'create-custom-scales':

                $rules = [
                    'scales' => 'required|array|size:'.config('app.max_grade'),
                    'scales.*' => 'required|array',
                ];

                foreach ($this->scales as $i => $scale)
                {

                    $prev = $this->scales[$i-1] ?? null;
                    $next = $this->sacles[$i+1] ?? null;
                    $next_start_score = $next['start_score'] ?? null;
                    $prev_end_score = $prev['end_score'] ?? null;
                    $prev_grade = $prev['grade'] ?? null;

                    $rules['scales.'.$i.'.grade'] = [
                        'required',
                        'integer',
                        /* диапазон оценки в рамках системы */
                        'between:1,'.config('app.max_grade'),

                        /* больше предыдушего на 1 балл, если есть предыдущая оценка */
                        function ($attribute, $value, $fail) use ($prev_grade, $i) {
                            if (isset($this->scales[$i - 1]) && is_int($this->scales[$i - 1]) && $prev_grade !== null && $value != $prev_grade + 1) {
                                $fail('Ожидается оценка '.($prev_grade + 1));
                            }

                            if (!isset($this->scales[$i - 2]) && $prev_grade === null && $value != 1) {
                                $fail('Ожидается оценка 1');
                            }
                        }
                    ];

                    $rules['scales.'.$i.'.start_score'] = [
                        'required',
                        'integer',
                        /* больше предыдущего конца диапазона на 1, если есть след диапазон */
                        function ($attribute, $value, $fail) use ($prev_end_score, $i) {
                            if (is_int($prev_end_score) && $prev_end_score !== null && $value != $prev_end_score + 1) {
                                $fail('Начало диапазона должно быть '.($prev_end_score + 1));
                            }

                            /* проверка, что самый первый диапазон должен начинаться с 0 */
                            if ($prev_end_score === null && $value != 0) {
                                $fail('Диапазон должен начинаться с 0');
                            }

                            /* меньше или равен текущему концу диапазона */
                            if (isset($this->scales[$i]['end_score']) && $value > $this->scales[$i]['end_score']) {
                                $fail('Начало диапазона должно быть меньше или равно '.$this->scales[$i]['end_score']);
                            }
                        },
                    ];

                    $rules['scales.'.$i.'.end_score'] = [
                        'required',
                        'integer',
                        /* должен быть на единицу меньше след начала, если есть след диапазон */
                        function ($attribute, $value, $fail) use ($next_start_score, $i) {
                            if ($next_start_score !== null && $value != $next_start_score - 1) {
                                $fail('Конец диапазона должен быть '.($next_start_score - 1));
                            }

                            /* должен быть больше или равен началу текущего диапазона */
                            if (isset($this->scales[$i]['start_score']) && $value < $this->scales[$i]['start_score']) {
                                $fail('Начало диапазона должно быть больше или равно '.$this->scales[$i]['start_score']);
                            }
                        },
                    ];

                }
                return $rules;

            /* удаление кастомной оценочной шкалы */
            case 'delete-custom-scales':
                return [];


            case 'add-indicator':
                return [
                    'indicator_id' => 'required|integer'
                ];
            case 'update-indicator-formula':
                return [
                    'indicator_id' => 'required|integer'
                ];

            case 'update-indicator':
                return [
                    'formula' => 'nullable|string',
                    'max_value' => 'nullable|integer',
                ];

            case 'delete-indicator':
                return [
                    'indicator_id' => 'required|integer'
                ];
            case 'add-access-organization-all':
                return [
                    'organization_id' => 'required|integer'
                ];
            case 'add-access-organization-role':
                return [
                    'organization_id' => 'required|integer',
                    'organization_role_id' => 'required|integer',
                ];
            case 'add-access-organization-card':
                return [
                    'organization_id' => 'required|integer',
                    'organization_user_card_templates_id' => 'required|integer',
                ];
            case 'add-access-organization-select':
                return [
                    'organization_id' => 'required|integer',
                    'organization_select_users_count' => 'required|integer'
                ];
            case 'add-access-organization-select-user':
                return [
                    'quiz_access_id' => 'required|integer',
                    'user_id' => 'required|integer'
                ];
            case 'remove-access-organization-select-user':
                return [
                    'quiz_access_id' => 'required|integer',
                    'user_id' => 'required|integer'
                ];
            case 'add-access-user':
                return [
                    'user_id' => 'required|integer'
                ];
            case 'add-access-users':
                return [
                    'users' => 'required|array'
                ];
            case 'add-access-filter':
                return [
                    'filter' => 'required|array',
                    'card_template_id' => 'required|integer',
                    'organization_id' => 'required|integer',
                ];
            case 'add-users-card-dynamic-filter':
                return [
                    'filter' => 'required|array',
                    'card_template_id' => 'required|integer',
                ];
            case 'remove-access':
                return [
                    'quiz_access_id' => 'required|integer'
                ];

            case 'add-result-access':
                return [
                    'type' => 'required',
                    'district_select' => 'required_if:type,district',
                    'organization_select' => 'required_if:type,organization',
                ];

            case 'remove-result-access':
                return [
                    'quiz_result_access_id' => 'required'
                ];

            /* выствить лучшую оценку для  */
            case 'set-best-scores-to-gradebook':
                return [
                    'user_id' => [
                        function ($attribute, $value, $fail)
                        {
                            $attempts = request('quiz')->attempts()->where('user_id', $this->user_id)->get();

                            /* пользователь должен был проходить этот тест */
                            if ($attempts->count() == 0)
                            {
                                $fail('Пользоватль не проходил текущий тест.');
                            }

                            /* у пользователя должны быть завершены все попытки */
                            foreach ($attempts as $attempt)
                            {
                                if (!in_array($attempt->status, ['SUCCESS', 'FAILED']))
                                {
                                    $fail('Попытка с id = '.$attempt->id.' у пользователя еще не завершена.');
                                }
                            }
                        },
                    ],
                ];


            /* если нет нужного действия */
            default:
                return ['action' => 'required|string|not_in:'.$this->action];
        }
    }

    public function messages()
    {
        $errors = [];
        $errors['bank_id.not_in'] = 'Вопросы из данного банка используются в тесте!';
        return $errors;
    }
}
