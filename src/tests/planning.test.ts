import { describe, it, expect, vi } from 'vitest';
import { PlanningEngine } from '../planning/PlanningEngine';

describe('PlanningEngine', () => {
  it('createPlan returns a draft plan with steps', () => {
    const engine = new PlanningEngine();
    const plan = engine.createPlan('Test Plan', 'Description', [
      { title: 'Step 1', description: 'Do step 1' },
      { title: 'Step 2', description: 'Do step 2' },
    ]);
    expect(plan.title).toBe('Test Plan');
    expect(plan.status).toBe('draft');
    expect(plan.steps).toHaveLength(2);
    expect(plan.steps[0].status).toBe('pending');
  });

  it('approvePlan sets status to approved', () => {
    const engine = new PlanningEngine();
    const plan = engine.createPlan('Plan', 'Desc', [{ title: 'S1', description: 'D1' }]);
    const approved = engine.approvePlan(plan);
    expect(approved.status).toBe('approved');
  });

  it('rejectPlan sets status to failed', () => {
    const engine = new PlanningEngine();
    const plan = engine.createPlan('Plan', 'Desc', [{ title: 'S1', description: 'D1' }]);
    const rejected = engine.rejectPlan(plan);
    expect(rejected.status).toBe('failed');
  });

  it('startExecution calls onStepUpdate for each step', async () => {
    const engine = new PlanningEngine();
    const plan = engine.createPlan('Plan', 'Desc', [
      { title: 'S1', description: 'D1' },
      { title: 'S2', description: 'D2' },
    ]);
    engine.approvePlan(plan);
    const updates = vi.fn();
    await engine.startExecution(plan, updates);
    expect(updates).toHaveBeenCalledTimes(4);
  });

  it('startExecution completes all steps', async () => {
    const engine = new PlanningEngine();
    const plan = engine.createPlan('Plan', 'Desc', [
      { title: 'S1', description: 'D1' },
    ]);
    engine.approvePlan(plan);
    const result = await engine.startExecution(plan, vi.fn());
    expect(result.status).toBe('completed');
    expect(result.steps[0].status).toBe('completed');
  });

  it('updateStep changes step status and output', () => {
    const engine = new PlanningEngine();
    const plan = engine.createPlan('Plan', 'Desc', [{ title: 'S1', description: 'D1' }]);
    const stepId = plan.steps[0].id;
    engine.updateStep(plan.id, stepId, 'running', 'Running now');
    const updated = engine.getPlan(plan.id)!;
    expect(updated.steps[0].status).toBe('running');
    expect(updated.steps[0].output).toBe('Running now');
  });
});
