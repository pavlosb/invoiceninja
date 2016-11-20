<?php namespace App\Ninja\Repositories;

use Auth;
use Session;
use App\Models\Client;
use App\Models\Task;

class TaskRepository extends BaseRepository
{
    public function getClassName()
    {
        return 'App\Models\Task';
    }

    public function find($clientPublicId = null, $filter = null)
    {
        $query = \DB::table('tasks')
                    ->leftJoin('clients', 'tasks.client_id', '=', 'clients.id')
                    ->leftJoin('contacts', 'contacts.client_id', '=', 'clients.id')
                    ->leftJoin('invoices', 'invoices.id', '=', 'tasks.invoice_id')
                    ->where('tasks.account_id', '=', Auth::user()->account_id)
                    ->where(function ($query) {
                        $query->where('contacts.is_primary', '=', true)
                                ->orWhere('contacts.is_primary', '=', null);
                    })
                    ->where('contacts.deleted_at', '=', null)
                    ->where('clients.deleted_at', '=', null)
                    ->select(
                        'tasks.public_id',
                        \DB::raw("COALESCE(NULLIF(clients.name,''), NULLIF(CONCAT(contacts.first_name, ' ', contacts.last_name),''), NULLIF(contacts.email,'')) client_name"),
                        'clients.public_id as client_public_id',
                        'clients.user_id as client_user_id',
                        'contacts.first_name',
                        'contacts.email',
                        'contacts.last_name',
                        'invoices.invoice_status_id',
                        'tasks.description',
                        'tasks.is_deleted',
                        'tasks.deleted_at',
                        'invoices.invoice_number',
                        'invoices.public_id as invoice_public_id',
                        'invoices.user_id as invoice_user_id',
                        'invoices.balance',
                        'tasks.is_running',
                        'tasks.time_log',
                        'tasks.created_at',
                        'tasks.user_id'
                    );

        if ($clientPublicId) {
            $query->where('clients.public_id', '=', $clientPublicId);
        }

        $this->applyFilters($query, ENTITY_TASK);

        if ($statuses = session('entity_status_filter:' . ENTITY_TASK)) {
            $statuses = explode(',', $statuses);
            $query->where(function ($query) use ($statuses) {
                if (in_array(TASK_STATUS_LOGGED, $statuses)) {
                    $query->orWhere('tasks.invoice_id', '=', 0)
                          ->orWhereNull('tasks.invoice_id');
                }
                if (in_array(TASK_STATUS_RUNNING, $statuses)) {
                    $query->orWhere('tasks.is_running', '=', 1);
                }
                if (in_array(TASK_STATUS_INVOICED, $statuses)) {
                    $query->orWhere('tasks.invoice_id', '>', 0);
                    if ( ! in_array(TASK_STATUS_PAID, $statuses)) {
                        $query->where('invoices.balance', '>', 0);
                    }
                }
                if (in_array(TASK_STATUS_PAID, $statuses)) {
                    $query->orWhere('invoices.balance', '=', 0);
                }
            });
        }

        if ($filter) {
            $query->where(function ($query) use ($filter) {
                $query->where('clients.name', 'like', '%'.$filter.'%')
                      ->orWhere('contacts.first_name', 'like', '%'.$filter.'%')
                      ->orWhere('contacts.last_name', 'like', '%'.$filter.'%')
                      ->orWhere('tasks.description', 'like', '%'.$filter.'%');
            });
        }

        return $query;
    }

    public function save($publicId, $data, $task = null)
    {
        if ($task) {
            // do nothing
        } elseif ($publicId) {
            $task = Task::scope($publicId)->withTrashed()->firstOrFail();
        } else {
            $task = Task::createNew();
        }

        if ($task->is_deleted) {
            return $task;
        }

        if (isset($data['client']) && $data['client']) {
            $task->client_id = Client::getPrivateId($data['client']);
        }
        if (isset($data['description'])) {
            $task->description = trim($data['description']);
        }

        if (isset($data['time_log'])) {
            $timeLog = json_decode($data['time_log']);
        } elseif ($task->time_log) {
            $timeLog = json_decode($task->time_log);
        } else {
            $timeLog = [];
        }

        array_multisort($timeLog);

        if (isset($data['action'])) {
            if ($data['action'] == 'start') {
                $task->is_running = true;
                $timeLog[] = [strtotime('now'), false];
            } else if ($data['action'] == 'resume') {
                $task->is_running = true;
                $timeLog[] = [strtotime('now'), false];
            } else if ($data['action'] == 'stop' && $task->is_running) {
                $timeLog[count($timeLog)-1][1] = time();
                $task->is_running = false;
            }
        }

        $task->time_log = json_encode($timeLog);
        $task->save();

        return $task;
    }

}
