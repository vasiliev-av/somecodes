<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

/**
 * модель теста
 */
class Quiz extends Model
{
    use SoftDeletes;

    protected $table = 'quizzes';

    protected $guarded = [];

    protected $hidden = [
        'deleted_at',
        'password',
    ];

    protected $dates = [
        'deleted_at',
    ];

    protected $casts = [
        'params' => 'array',
    ];

    protected $appends = [
        'count_questions',      // кол-во вопросов в тесте, определяется по правилам
        'use_default_scales',   // используется оценочная шкала по-умолчанию или кастомная
        'max_score',            // максимальное кол-во баллов, которой можно набрать продя полностью тест
        'can_editable',         // можно ли редактировать и добавлять правила в тест
        //'average_time',         // среднее время прохождения теста пользователями
        'count_rules',          //правил в тесте
        'attempts_count'
    ];

    /**
     * @var array - все доступные типы
     */

    public static $types = [
        'test',         // тесты с оценкой
        'interview',    // опрос без ценки
    ];

    public static $errors = [];

    protected static function boot()
    {
        parent::boot();

        static::deleting(function ($model) {
            DB::beginTransaction();

            /* если хотя бы есть одна попытка - отменить транзакцию */
            if ($model->attempts->first())
            {
                self::$errors[] = 'Попыток: '.$model->attempts->count().'. Необходимо удалить их.';
                DB::rollback();
                return false;
            }

            /* удалить все вопросы теста */
            foreach ($model->questions as $question)
            {
                if (!$question->delete())
                {
                    self::$errors[] = 'Во время удаления вопроса произошла ошибка.';
                    self::$errors = array_merge($question->getErrors(), self::$errors);
                    DB::rollback();
                    return false;
                }
            }

            DB::commit();
            return true;
        });

        static::creating(function ($model) {
            $model->creator = auth()->id();
            $model->editor = auth()->id();
        });

        static::updating(function ($model) {
            $model->editor = auth()->id();
            $model->forgetCache();
        });

        static::saving(function ($model) {
            $model->editor = auth()->id();
        });

        static::deleted(function ($quiz) {
            $quiz->forgetCache();
        });
    }


    /********** RELATIONSHIPS START ********************/

        /**
         * модель, к которой привязывается тест
         */
        public function model()
        {
            return $this->morphTo();
        }

        public function profiles(){
            return $this->morphedByMany(Profile::class, 'model', 'model_has_profile', 'model_id', 'profile_id');
        }

        /**
         * вопросы теста и правила формирования этих вопросов
         */
        public function questions()
        {
            return $this->hasMany(QuizQuestion::class, 'quiz_id');
        }

        /**
         * попытки прохождения теста
         */
        public function attempts()
        {
            return $this->hasMany(QuizAttempt::class, 'quiz_id');
        }

        /**
         * создатель теста
         */
        public function creator()
        {
            return $this->belongsTo(User::class, 'creator');
        }

        /**
         * пользователь, который последний раз редактировал тест
         */
        public function editor()
        {
            return $this->belongsTo(User::class, 'editor');
        }

        /**
         * шкалы оценок для теста
         */
        public function gradingScales()
        {
            return $this->hasMany(QuizGradingScale::class, 'quiz_id');
        }

        public function indicators(){
            return $this->belongsToMany(Indicator::class, 'quiz_indicator')->withPivot(['formula', 'max_value'])->withTimestamps();
        }

        public function accesses(){
            return $this->hasMany(QuizAccess::class)->whereNull('relation_id');
        }

        public function result_accesses(){
            return $this->hasMany(QuizResultAccess::class, 'quiz_id');
        }

        public function accessOrganizations(){
            return $this->morphedByMany(Organization::class, 'model', 'quiz_access')->wherePivot('type', 'all')->withTimestamps();
        }

        public function accessOrganizationsSelect(){
            return $this->morphedByMany(Organization::class, 'model', 'quiz_access')
                ->wherePivot('type', 'select')
                ->withTimestamps()
                ->withPivot(['count', 'id']);
        }

        public function accessFilter(){
            return $this->morphedByMany(Organization::class, 'model', 'quiz_access')
                ->wherePivot('type', 'filter')
                ->withTimestamps()
                ->withPivot(['count', 'id', "filter"]);
        }

        public function accessUsersSelect(){
            return $this->morphedByMany(User::class, 'model', 'quiz_access')->whereNotNull('relation_id')->withTimestamps();
        }

        public function accessUsers(){
            return $this->morphedByMany(User::class, 'model', 'quiz_access')->whereNull('relation_id')->withTimestamps();
        }

        public function users()
        {
            return $this->morphToMany(User::class, 'model', 'model_has_users')->withTimestamps();
        }

    /********** RELATIONSHIPS FINISH ********************/

    /********** MUTATORS START ********************/


        /**
         * Кол-во вопросов, которое будет содержаться в попытке по этому тесту
         */
        public function getCountQuestionsAttribute() : Int
        {
            return Cache::remember('quiz_'.$this->id.'_common_questions_count', 365 * 24 * 60, function () {
                $count = 0;
                $this->load('questions.model');

                foreach ($this->questions as $question)
                {
                    switch ($question->type_rule)
                    {
                        /* конкретный вопрос */
                        case 'specific-question':
                            $count++;
                            break;

                        /* все вопросы из банка */
                        case 'all-questions-from-bank':
                        /* рандомные вопросы из банка */
                        case 'random-questions-from-bank':
                            $count += $question->count_questions;
                            break;
                    }
                }

                return $count;
            });
        }

    public function getCountRulesAttribute() : Int
    {
        return $this->questions()->count();
//        return Cache::remember('quiz_'.$this->id.'_rules_count', 365 * 24 * 60, function () {
//
//        });
    }

        /**
         * set-мутатор на изменение своего ответа
         */
        public function setChangeAnswersAttribute(bool $value): void
        {
            $params = json_decode($this->attributes['params'] ?? '', true) ?? [];
            $params['change_answers'] = $value;
            $this->attributes['params'] = json_encode($params);
        }

        /**
         * set-мутатор на кол-во попыток
         */
        public function setCountAttemptsAttribute(int $value): void
        {
            $params = json_decode($this->attributes['params'] ?? '', true) ?? [];
            $params['count_attempts'] = $value;
            $this->attributes['params'] = json_encode($params);
        }

        /**
         * set-мутатор на просмотр подробных результатов
         */
        public function setSeeResultQuestionsAttribute(bool $value): void
        {
            $params = json_decode($this->attributes['params'] ?? '', true) ?? [];
            $params['see_result_questions'] = $value;
            $this->attributes['params'] = json_encode($params);
        }

        /**
         * set-мутатор на правило права доступа
         */
        public function setAvailableRuleAttribute(string $value): void
        {
            $params = json_decode($this->attributes['params'] ?? '', true) ?? [];
            $params['available']['rule'] = $value;
            $this->attributes['params'] = json_encode($params);
        }

        /**
         * set-мутатор на роль права доступа
         */
        public function setAvailableRoleIdAttribute(string $value): void
        {
            $params = json_decode($this->attributes['params'] ?? '', true) ?? [];
            $params['available']['role_id'] = $value;
            $this->attributes['params'] = json_encode($params);
        }


        /**
         * Мутатор служит значением по-умолчанию для связи gradingScales
         *
         * @return Collection
         */
        public function getGradingScalesAttribute()
        {
            $gradings = $this
                ->gradingScales()
                ->remember(now()->subDays(365), 'quiz_'.$this->id.'_grading_scales')
                ->get();

            $count = $this->gradingScalesCount();

            return !$count
                ? QuizGradingScale::getDefaultCollection()
                : $gradings;
        }

        /**
         * используется ли шкала по-умолчанию
         *
         * @return bool
         */
        public function getUseDefaultScalesAttribute(): bool
        {
            return !$this->gradingScalesCount();
        }

        /**
         * Максимальное кол-во баллов, которое можно набрать по попытке этого теста
         *
         * @return int
         */
        public function getMaxScoreAttribute(): int
        {
            return Cache::remember('quiz_'.$this->id.'_max_score', 365 * 24 * 60, function () {
                $maxScore = 0;
                foreach ($this->questions as $question)
                {
                    $maxScore += $question->max_score;
                }
                return $maxScore;
            });
        }

        /**
         * Можно ли в тест добавлять вопросы
         *
         * @return bool
         */
        public function getCanEditableAttribute(): bool
        {
            return !$this
                ->attempts()
                ->remember(now()->addDays(365), 'quiz_'.$this->id.'_attempts_count')
                ->count();
        }

        /**
         * Мутатор среднее время прохождения теста пользователями
         *
         * @return среднее время
         */
        public function getAverageTimeAttribute()
        {
            return $this->averageTime();
        }

        public function getAttemptsCountAttribute()
        {
            return $this->attempts()->count();
        }

    /********** MUTATORS FINISH ********************/


    public function getErrors()
    {
        return self::$errors;
    }

    /**
     * Сброс кэша
     *      quiz_{id}_grading_scales
     *      quiz_{id}_max_score
     *      quiz_{id}_attempts_count
     *      quiz_{id}_grading_scales_count
     */
    public function forgetCache()
    {
        Cache::forget('quiz_'.$this->id.'_grading_scales');
        Cache::forget('quiz_'.$this->id.'_max_score');
        Cache::forget('quiz_'.$this->id.'_attempts_count');
        Cache::forget('quiz_'.$this->id.'_grading_scales_count');
        Cache::forget('quiz_'.$this->id.'_common_questions_count');

    }

    /**
     *
     */
    public function gradingScalesCount(): int
    {
        return $this
            ->gradingScales()
            ->remember(now()->addDays(365), 'quiz_'.$this->id.'_grading_scales_count')
            ->count();
    }

    /**
     * Расчет среднего времени прохождения теста. Если не указывать пользователя, тогда за все попытки посчитает.
     *
     * @param ?int $user_id = null - id пользователя, среднее время которого нужно посчитать
     * @return int - среднее время прохождения теста
     */
    public function averageTime(?int $user_id = null): int
    {
        // расчет среднего времени по всем завершенным попыткам
        $attempts = $this->attempts();

        /* попытки для конкретного пользователя */
        if ($user_id) $attempts->where('user_id', $user_id);

        $allTime = 0;
        $count = 0;

        $attempts = $attempts->get();
        foreach ($attempts as $attempt)
        {
            if ($attempt->duration_time !== false)
            {
                $allTime += $attempt->duration_time;
                $count++;
            }
        }

        if ($count)
            $averageTime = $allTime / $count;

        return isset($averageTime) ? ((int)$averageTime) : false;
    }

    /**
     * Создание/пересоздание шкалы для теста
     *
     * @return bool
     */
    public function createScales(array $scales)
    {
        DB::beginTransaction();
        $result = $this->deleteScales();
        try {
            foreach ($scales as $scale)
            {
                $result &= (bool) $this->gradingScales()->create([
                    'grade' => $scale['grade'],
                    'start_score' => $scale['start_score'],
                    'end_score' => $scale['end_score'],
                ]);
            }
        } catch (\Exception $e) {
            DB::rollback();
            return false;
        }

        if ($result)
            DB::commit();
        else
            DB::rollback();

        return true;
    }

    /**
     * Удаление оценочной шклаы
     *
     * @return bool
     */
    public function deleteScales()
    {
        DB::beginTransaction();
        $result = true;

        foreach ($this->gradingScales()->get() as $scale)
        {
            $result &= $scale->delete();
        }

        if ($result)
            DB::commit();
        else
            DB::rollback();

        return true;
    }

    /**
     * Создание теста для указанной модели
     *
     * @param Model $model - экзепляр модели, для которой создается
     * @param int $author_id - id пользователя, который создает
     * @param array $data - данные для моздания теста
     *
     * @return ?Quiz
     */
    public static function createForModel(Model $model, int $author_id, array $data): ?Quiz
    {
        $author = User::find($author_id);
        if (!$author) return null;

        $quiz = self::create([
            'creator' => $author->id,
            'editor' => $author->id,
            'model_type' => get_class($model),
            'model_id' => $model->id,
            'lead_time' => $data['lead_time'],
            'name' => $data['name'] ?? '',
            'start_time_availability' => $data['start_time_availability'],
            'end_time_availability' => $data['end_time_availability'],
            'params' => $data['params'],
        ]);

        return $quiz;
    }

    /**
     * удаление одного правила формирования вопросов или одного вопроса в тесте
     *
     * @param int $quiz_question_id - id правила формирования
     * @return bool
     */
    public function deleteOneQuestionRule(int $quiz_question_id): bool
    {
        $questionRule = $this->questions()->find($quiz_question_id);
        $this->forgetCache();
        return $questionRule && $questionRule->delete();
    }

    public function updateOneQuestionRule(int $quiz_question_id, int $point_count): bool
    {
        $questionRule = $this->questions()->find($quiz_question_id);
        $this->forgetCache();

        $props = $questionRule->props;
        $props['point_count'] = $point_count;
        return $questionRule && $questionRule->update(['props' => $props]);
    }

    /**
     * Удаление всех правил формирования вопросов в тесте
     *
     * @return bool
     */
    public function deleteAllQuestionRule(): bool
    {
        DB::beginTransaction();
        foreach ($this->questions as $question)
        {
            if (!$question->delete())
            {
                DB::rollback();
                return false;
            }
        }
        DB::commit();
        return true;
    }

    /**
     * Создание попытки прохождения теста
     *
     * @param int $user_id = null - для какого пользователя создать тест
     * @param bool $ignoreTimeAvailable = false - игнорировать время доступности теста
     * @param bool $delayedStart = false - отложенный старт
     * @return false or QuizAttempt
     */
    public function createAttempt(int $user_id = null, bool $ignoreTimeAvailable = false, bool $delayedStart = false)
    {
        /* проверка доступности теста по времени */
        if (!$ignoreTimeAvailable && ($this->start_time_availability > now() || $this->end_time_availability < now()))
        {
            self::$errors[] = 'Тест или опрос не доступен по времени.';
            return false;
        }

        $quizAttempt = $this->attempts()->create([
            'user_id' => $user_id ?? auth()->id(),
            'start_time' => $delayedStart ? null : now(),
            'finished_at' => $delayedStart ? null : now()->add('minutes', $this->lead_time),
        ]);

        if (!$quizAttempt->id || !$quizAttempt->questionGenerate())
        {
            self::$errors[] = 'Не получилось создать попытку или сгенерировать вопросы для нее.';
            return false;
        }

        return $quizAttempt;
    }

    /**
     * Создание попыток прохождения по списку лоигнов
     */
    public function createAttemptByLogins(array $logins = [])
    {
        if ($this->start_time_availability > now() || $this->end_time_availability < now())
        {
            self::$errors[] = 'Тест или опрос не доступен по времени.';
            return false;
        }

        foreach (User::whereIn('login', $logins)->pluck('id') as $user_id)
        {
            $quizAttempt = $this->attempts()->create([
                'user_id' => $user_id,
                'start_time' => now(),
                'finished_at' => now()->add('minutes', $this->lead_time),
            ]);

            if (!$quizAttempt->id || !$quizAttempt->questionGenerate())
            {
                self::$errors[] = 'Не получилось создать попытку или сгенерировать вопросы для нее.';
                return false;
            }
        }
        return true;
    }

    /**
     * Удаление всех попыток ползователей для текущего теста
     */
    public function deleteAllUsersAttempts(array $logins = [])
    {
        $user_ids = User::whereIn('id', $logins)->orWhereIn('login', $logins)->pluck('id');
        $this->attempts()->whereIn('user_id', $user_ids)->update(['deleted_at' => now()]);
        return true;
    }

    /**
     * выставить лучшую оценку среди всех попыток текущего теста по пользователям
     *
     * @param int $user_id = null - id пользователя
     * @return bool
     */
    public function setBestScoresToGradebook(?int $user_id = null): bool
    {
        // TODO если проставлена уже оценка по попыткам - пропустить пользователя
        DB::beginTransaction();
        $result = true;
        /* тест должен быть тестом и быть привязанным к элементу урока, чтобы можно было определить id урока для журнала */
        if ($this->type != 'test' || $this->model_type != CourseModuleElement::class)
        {
            self::$errors[] = 'Либо тип не test, либо модель привязана не к элементу урока.';
            return false;
        }

        /* все попытки теста с группировкой по пользователям, которые проходили/проходят */
        $attempts = $this->attempts();

        /* если нужно поставить лучшую оценку по одному пользователю */
        if ($user_id) $attempts->where('user_id', $user_id);
        $attempts = $attempts->get()->groupBy('user_id');
        foreach ($attempts as $user_id => $userAttempts)
        {
            /* если пользователь ни разу не начинал - пропустить */
            if (!$userAttempts->count()) continue;

            /* поиск незавершенной попытки пользователя по данному тесту */
            $continue = false;
            foreach ($userAttempts as $attempt)
            {
                $continue = !in_array($attempt->status, ['SUCCESS', 'FAILED']);
                if ($continue) break;
            }

            /* если такие попытки были найдены - пропустить текущего пользователя */
            if ($continue) continue;

            /* поиск наилучшей попытки пользователя */
            $max = null;
            foreach ($userAttempts as $attempt)
                if (!$max || $max->attemptResult->score < $attempt->attemptResult->score)
                    $max = $attempt;

            /* получение и выставление оценки в журнал */
            $scoreForGradebook = $max->getScoreByGradingScales();
            $gradebook = Gradebook::createOrFirstModel($user_id, $this->model->id);
            // $result &= $gradebook->addScore($scoreForGradebook);

            /* устанавливаем указатель на оценку, а отметка put_in_gradebook ставится в мутаторе QuizAttempt */
            $result &= $max->update(['gradebook_id' => $gradebook->id]);

            if (!$result)
            {
                self::$errors[] = 'Не удалось добавить оценку в журнал.';
                self::$errors = array_merge(self::$errors, $gradebook->getErrors());
                break;
            }
        }

        if ($result) DB::commit();
        else DB::rollback();


        return $result;
    }
}
