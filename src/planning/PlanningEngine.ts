import type { Plan, PlanStep } from '../types';

type StepUpdateCallback = (planId: string, step: PlanStep) => void;

export class PlanningEngine {
  private plans: Map<string, Plan> = new Map();

  createPlan(
    title: string,
    description: string,
    steps: Array<{ title: string; description: string }>
  ): Plan {
    const plan: Plan = {
      id: this.generateId(),
      title,
      description,
      steps: steps.map((s) => ({
        id: this.generateId(),
        title: s.title,
        description: s.description,
        status: 'pending',
      })),
      status: 'draft',
    };
    this.plans.set(plan.id, plan);
    return plan;
  }

  approvePlan(plan: Plan): Plan {
    const stored = this.plans.get(plan.id);
    if (!stored) throw new Error(`Plan ${plan.id} not found`);
    stored.status = 'approved';
    return stored;
  }

  rejectPlan(plan: Plan): Plan {
    const stored = this.plans.get(plan.id);
    if (!stored) throw new Error(`Plan ${plan.id} not found`);
    stored.status = 'failed';
    return stored;
  }

  async startExecution(plan: Plan, onStepUpdate: StepUpdateCallback): Promise<Plan> {
    const stored = this.plans.get(plan.id);
    if (!stored) throw new Error(`Plan ${plan.id} not found`);
    stored.status = 'executing';

    for (const step of stored.steps) {
      step.status = 'running';
      onStepUpdate(stored.id, { ...step });

      await new Promise<void>((resolve) => setTimeout(resolve, 0));

      step.status = 'completed';
      step.output = `Step "${step.title}" completed.`;
      onStepUpdate(stored.id, { ...step });
    }

    stored.status = 'completed';
    return stored;
  }

  updateStep(planId: string, stepId: string, status: PlanStep['status'], output?: string): void {
    const plan = this.plans.get(planId);
    if (!plan) return;
    const step = plan.steps.find((s) => s.id === stepId);
    if (!step) return;
    step.status = status;
    if (output !== undefined) step.output = output;
  }

  getPlan(id: string): Plan | undefined {
    return this.plans.get(id);
  }

  private generateId(): string {
    return `${Date.now()}-${Math.random().toString(36).slice(2, 9)}`;
  }
}
