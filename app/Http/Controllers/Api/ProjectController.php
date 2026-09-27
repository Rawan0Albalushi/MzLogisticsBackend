<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\AttachProjectJobRequest;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\TransportJob;
use App\Models\User;
use App\Support\ApiResponse;
use App\Support\ListFilters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ProjectController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Project::class);
        $user = $request->user();

        $projects = Project::query()
            ->visibleTo($user)
            ->withCount(['jobs' => fn (Builder $jobs) => $this->constrainJobs($jobs, $user)])
            ->when($request->filled('search'), function ($query) use ($request) {
                ListFilters::search(
                    $query,
                    $request->string('search')->toString(),
                    ['project_id', 'name_en', 'name_ar'],
                );
            })
            ->latest()
            ->paginate((int) $request->integer('per_page', 15));

        return ApiResponse::success(ProjectResource::collection($projects));
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $project = Project::query()->create($request->validated());

        return ApiResponse::success(ProjectResource::make($project), 'Project created.', 201);
    }

    public function show(Request $request, Project $project): JsonResponse
    {
        $this->authorize('view', $project);

        return ApiResponse::success(ProjectResource::make($this->loadProject($project, $request->user())));
    }

    public function update(UpdateProjectRequest $request, Project $project): JsonResponse
    {
        $project->fill($request->validated())->save();

        return ApiResponse::success(
            ProjectResource::make($this->loadProject($project, $request->user())),
            'Project updated.',
        );
    }

    public function attachJob(AttachProjectJobRequest $request, Project $project): JsonResponse
    {
        $job = TransportJob::query()->findOrFail($request->integer('job_id'));

        if ($job->project_id !== null && $job->project_id !== $project->id) {
            throw ValidationException::withMessages([
                'job_id' => ['This job is already linked to another project.'],
            ]);
        }

        $job->forceFill(['project_id' => $project->id])->save();

        return ApiResponse::success(
            ProjectResource::make($this->loadProject($project, $request->user())),
            'Job linked.',
        );
    }

    public function detachJob(Request $request, Project $project, TransportJob $job): JsonResponse
    {
        $this->authorize('update', $project);
        abort_unless($job->project_id === $project->id, 404);

        $job->forceFill(['project_id' => null])->save();

        return ApiResponse::success(
            ProjectResource::make($this->loadProject($project, $request->user())),
            'Job unlinked.',
        );
    }

    private function loadProject(Project $project, User $user): Project
    {
        return $project->loadCount([
            'jobs' => fn (Builder $jobs) => $this->constrainJobs($jobs, $user),
        ])->load([
            'jobs' => function (HasMany $jobs) use ($user) {
                $this->constrainJobs($jobs, $user);
                $jobs->with(['customerOrganization', 'providerOrganization'])->latest();
            },
        ]);
    }

    private function constrainJobs(Builder|HasMany $query, User $user): void
    {
        if ($user->isProvider()) {
            $query->where('provider_organization_id', $user->organization_id);
        }
    }
}
