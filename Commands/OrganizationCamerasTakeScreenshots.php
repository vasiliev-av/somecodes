<?php

namespace App\Console\Commands;

use App\Models\OrganizationCamera;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

class OrganizationCamerasTakeScreenshots extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'organization_cameras:take_screen';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Захват фото с IP камер организаций';

    /**
     * Create a new command instance.
     *
     * @return void
     */
    public function __construct()
    {
        parent::__construct();
    }

    /**
     * Execute the console command.
     *
     * @return mixed
     */
    public function handle()
    {
        //
        if (
            today()->addHours(7) < now()
            &&
            now() < today()->addHours(20)
        ) {
            $cameras = OrganizationCamera::get();
            foreach ($cameras as $camera) {
                if ($camera->checkCameraAvailable()){
                    $path = 'cameras/'.Carbon::now()->format('Y/m/d').'/'.$camera->id.'/'.Carbon::now()->unix()."/";
                    Storage::makeDirectory('public/'.$path);
                    $storage_path = storage_path('app/public/'.$path);
                    $name = "%03d_%04d.jpeg";
                    system('ffmpeg -rtsp_transport tcp -i "'.$camera->url.'" -y -f image2 -r 2 -frames 8 '.$storage_path.$name);
                }
            }
        }
    }
}
