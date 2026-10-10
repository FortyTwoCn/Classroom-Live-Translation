import { api, type Course } from "./api";
// Option lists must include later pages, while the management table itself
// keeps its normal server pagination.
export async function loadAdminCourseOptions(): Promise<Course[]> {
  const courses: Course[] = [];
  let page = 1,
    lastPage = 1;
  do {
    const result = await api.request<{
      courses: { data: Course[]; last_page: number };
    }>(`/api/admin/courses?page=${page}`);
    courses.push(...result.courses.data);
    lastPage = result.courses.last_page;
    page++;
  } while (page <= lastPage);
  return courses;
}
