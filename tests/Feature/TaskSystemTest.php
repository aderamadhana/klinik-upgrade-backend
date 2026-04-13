<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Task;
use App\Models\Status;
use App\Services\AuthService;
use App\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

class TaskSystemTest extends TestCase
{
    use RefreshDatabase;

    protected AuthService $auth;
    protected TaskService $task;

    protected function setUp(): void
    {
        parent::setUp();

        $this->auth = new AuthService();
        $this->task = new TaskService();

        // Insert 4 status
        // Status::insert([
        //     ['id' => 1, 'name' => 'To Do'],
        //     ['id' => 2, 'name' => 'Doing'],
        //     ['id' => 3, 'name' => 'Done'],
        //     ['id' => 4, 'name' => 'Canceled'],
        // ]);
    }

    /** @test */
    public function full_task_flow_based_on_business_rules()
    {
        /* ===============================================================
         * [1] Pemggunaan dibuat
         * ===============================================================*/
        fwrite(STDOUT, "\n[1] Membuat pengguna manager dan staff ... ");

        $manager = User::factory()->create([
            'role_id' => 2,
            'email'   => 'manager@test.com',
            'password'=> Hash::make('password')
        ]);

        $staff = User::factory()->create([
            'role_id'   => 1,
            'email'     => 'staff@test.com',
            'password'  => Hash::make('password'),
            'manager_id'=> $manager->id
        ]);

        $this->assertDatabaseCount('users', 2);
        fwrite(STDOUT, "PASS\n");


        /* ===============================================================
         * [2] Login Manager
         * ===============================================================*/
        fwrite(STDOUT, "[2] Login manager ... ");

        $loginRes = $this->auth->processLogin([
            'email'    => 'manager@test.com',
            'password' => 'password'
        ]);

        $this->assertArrayHasKey('token', $loginRes);
        $this->actingAs($manager);

        fwrite(STDOUT, "PASS\n");


        /* ===============================================================
         * [3] Manager membuat tugas
         * ===============================================================*/
        fwrite(STDOUT, "[3] Manager membuat tugas untuk staff ... ");

        $createRes = $this->task->saveTask([
            'title'       => 'Laporan Mingguan',
            'description' => 'Deskripsi tugas awal',
            'assignee_id' => $staff->id
        ]);

        $createRes = TestResponse::fromBaseResponse($createRes);
        $createRes->assertStatus(200);

        $taskId = $createRes['data']['id'];

        fwrite(STDOUT, "PASS\n");


        /* ===============================================================
         * [4] Update tugas oleh creator
         * ===============================================================*/
        fwrite(STDOUT, "[4] Creator mengubah tugas ... ");

        $updateRes = $this->task->updateTask([
            'title'       => 'Laporan Mingguan (Revisi)',
            'description' => 'Deskripsi revisi',
            'assignee_id' => $staff->id
        ], $taskId);

        $updateRes = TestResponse::fromBaseResponse($updateRes);
        $updateRes->assertStatus(200);

        fwrite(STDOUT, "PASS\n");


        /* ===============================================================
         * [5] Status: To Do → Doing
         * ===============================================================*/
        fwrite(STDOUT, "[5] Mengubah status tugas To Do → Doing ... ");

        $statusDoing = $this->task->updateTaskStatus([
            'status_id' => 2
        ], $taskId);

        $statusDoing = TestResponse::fromBaseResponse($statusDoing);
        $statusDoing->assertStatus(200);

        fwrite(STDOUT, "PASS\n");


        /* ===============================================================
         * [6] Isi report (hanya di status Doing)
         * ===============================================================*/
        fwrite(STDOUT, "[6] Mengisi report saat Doing ... ");

        $reportRes = $this->task->updateTaskReport([
            'report' => 'Progress 50%'
        ], $taskId);

        $reportRes = TestResponse::fromBaseResponse($reportRes);
        $reportRes->assertStatus(200);

        fwrite(STDOUT, "PASS\n");


        /* ===============================================================
         * [7] Status: Doing → Done
         * ===============================================================*/
        fwrite(STDOUT, "[7] Mengubah status Doing → Done ... ");

        $toDone = $this->task->updateTaskStatus([
            'status_id' => 3
        ], $taskId);

        $toDone = TestResponse::fromBaseResponse($toDone);
        $toDone->assertStatus(200);

        fwrite(STDOUT, "PASS\n");


        /* ===============================================================
         * [8] Get Task by ID
         * ===============================================================*/
        fwrite(STDOUT, "[8] Mengambil detail task ... ");

        $getRes = $this->task->getTaskById($taskId);
        $getRes = TestResponse::fromBaseResponse($getRes);

        $getRes->assertStatus(200);
        $getRes->assertJson([
            'status' => true,
            'data'   => ['id' => $taskId]
        ]);

        fwrite(STDOUT, "PASS\n\n");
    }
}
