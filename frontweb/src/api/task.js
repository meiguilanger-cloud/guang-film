import request from '@/utils/request'

export const taskAPI = {
  get(taskId) {
    return request.get(`/tasks/${taskId}`)
  },
  listByResource(resourceId) {
    return request.get('/tasks', { params: { resource_id: String(resourceId) } })
  },
}
